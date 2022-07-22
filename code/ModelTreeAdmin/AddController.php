<?php

namespace SilverStripe\Admin\ModelTreeAdmin;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\SelectionGroup;
use SilverStripe\Forms\SelectionGroup_Item;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Security;
use SilverStripe\SiteConfig\SiteConfig;

class AddController extends EditController
{
    private static $allowed_actions = [
        'AddForm',
        'doAdd',
        'doCancel'
    ];

    /**
     * @return Form
     */
    public function AddForm()
    {
        $pageTypes = [];
        $defaultIcon = Config::inst()->get(SiteTree::class, 'icon_class');

        foreach ($this->treeAdmin->PageTypes() as $type) {
            $class = $type->getField('ClassName');
            $icon = Config::inst()->get($class, 'icon_class') ?: $defaultIcon;

            // If the icon is the SiteTree default and there's some specific icon being provided by `getPageIconURL`
            // then we don't need to add the icon class. Otherwise the class take precedence.
            if ($icon === $defaultIcon && !empty(singleton($class)->getPageIconURL())) {
                $icon = '';
            }

            $html = sprintf(
                '<span class="page-icon %s class-%s"></span><span class="title">%s</span><span class="form__field-description">%s</span>',
                $icon,
                Convert::raw2htmlid($class),
                $type->getField('AddAction'),
                $type->getField('Description')
            );
            $pageTypes[$class] = DBField::create_field('HTMLFragment', $html);
        }
        // Ensure generic page type shows on top
        if (isset($pageTypes['Page'])) {
            $pageTitle = $pageTypes['Page'];
            $pageTypes = array_merge(['Page' => $pageTitle], $pageTypes);
        }

        $numericLabelTmpl = '<span class="step-label"><span class="flyout">Step %d. </span><span class="title">%s</span></span>';

        $topTitle = _t('SilverStripe\\CMS\\Controllers\\CMSPageAddController.ParentMode_top', 'Top level');
        $childTitle = _t('SilverStripe\\CMS\\Controllers\\CMSPageAddController.ParentMode_child', 'Under another page');

        $fields = new FieldList(
            $parentModeField = new SelectionGroup(
                "ParentModeField",
                [
                    $topField = new SelectionGroup_Item(
                        "top",
                        null,
                        $topTitle
                    ),
                    new SelectionGroup_Item(
                        'child',
                        $parentField = new TreeDropdownField(
                            "ParentID",
                            "",
                            SiteTree::class,
                            'ID',
                            'TreeTitle'
                        ),
                        $childTitle
                    )
                ]
            ),
            new LiteralField(
                'RestrictedNote',
                sprintf(
                    '<p class="alert alert-info message-restricted">%s</p>',
                    _t(
                        'SilverStripe\\CMS\\Controllers\\CMSMain.AddPageRestriction',
                        'Note: Some page types are not allowed for this selection'
                    )
                )
            ),
            $typeField = new OptionsetField(
                "PageType",
                DBField::create_field(
                    'HTMLFragment',
                    sprintf($numericLabelTmpl ?? '', 2, _t('SilverStripe\\CMS\\Controllers\\CMSMain.ChoosePageType', 'Choose page type'))
                ),
                $pageTypes,
                'Page'
            )
        );

        $parentModeField->setTitle(DBField::create_field(
            'HTMLFragment',
            sprintf($numericLabelTmpl ?? '', 1, _t('SilverStripe\\CMS\\Controllers\\CMSMain.ChoosePageParentMode', 'Choose where to create this page'))
        ));

        $parentField->setSearchFunction(function ($sourceObject, $labelField, $search) {
            return DataObject::get($sourceObject)
                ->filterAny([
                    'MenuTitle:PartialMatch' => $search,
                    'Title:PartialMatch' => $search,
                ]);
        });

        // TODO Re-enable search once it allows for HTML title display,
        // see http://open.silverstripe.org/ticket/7455
        // $parentField->setShowSearch(true);

        $parentModeField->addExtraClass('parent-mode');

        // CMSMain->currentPageID() automatically sets the homepage,
        // which we need to counteract in the default selection (which should default to root, ID=0)
        if ($parentID = $this->getRequest()->getVar('ParentID')) {
            $parentModeField->setValue('child');
            $parentField->setValue((int)$parentID);
        } else {
            $parentModeField->setValue('top');
        }

        // Check if the current user has enough permissions to create top level pages
        // If not, then disable the option to do that
        if (!SiteConfig::current_site_config()->canCreateTopLevel()) {
            $topField->setDisabled(true);
            $parentModeField->setValue('child');
        }

        $actions = new FieldList(
            FormAction::create('doAdd', _t('SilverStripe\\CMS\\Controllers\\CMSMain.Create', 'Create'))
                ->addExtraClass('btn-primary font-icon-plus-circled')
                ->setUseButtonTag(true),
            FormAction::create('doCancel', _t('SilverStripe\\CMS\\Controllers\\CMSMain.Cancel', 'Cancel'))
                ->addExtraClass('btn-secondary')
                ->setUseButtonTag(true)
        );

        $this->treeAdmin->extend('updatePageOptions', $fields);

        $negotiator = $this->treeAdmin->getResponseNegotiator();
        $form = Form::create(
            $this->treeAdmin,
            'AddForm',
            $fields,
            $actions
        )->setHTMLID('Form_AddForm')->setStrictFormMethodCheck(false);
        $form->setAttribute('data-hints', $this->treeAdmin->SiteTreeHints());
        $form->setAttribute('data-childfilter', $this->treeAdmin->Link('childfilter'));
        $form->setValidationResponseCallback(function (ValidationResult $errors) use ($negotiator, $form) {
            $request = $this->getRequest();
            if ($request->isAjax() && $negotiator) {
                $result = $form->forTemplate();
                return $negotiator->respond($request, [
                    'CurrentForm' => function () use ($result) {
                        return $result;
                    }
                ]);
            }
            return null;
        });
        $form->addExtraClass('flexbox-area-grow fill-height cms-add-form cms-content cms-edit-form ' . $this->getBaseCSSClasses());
        $form->setTemplate($this->treeAdmin->getTemplatesWithSuffix('_EditForm'));

        return $form;
    }

    /**
     * @param array $data
     * @param Form $form
     * @return HTTPResponse
     */
    public function doAdd($data, $form)
    {
        $treeClass = $this->treeAdmin->getTreeClass();
        $className = isset($data['PageType']) ? $data['PageType'] : $treeClass;
        $parentID = isset($data['ParentID']) ? (int)$data['ParentID'] : 0;

        if (!$parentID && isset($data['Parent']) && method_exists($treeClass, 'get_by_link')) {
            $page = $treeClass::get_by_link($data['Parent']);
            if ($page) {
                $parentID = $page->ID;
            }
        }

        if (is_numeric($parentID) && $parentID > 0) {
            $parentObj = $this->treeAdmin->getTreeClass()::get()->byID($parentID);
        } else {
            $parentObj = null;
        }

        if (!$parentObj || !$parentObj->ID) {
            $parentID = 0;
        }

        if (!singleton($className)->canCreate(Security::getCurrentUser(), ['Parent' => $parentObj])) {
            return Security::permissionFailure($this);
        }

        $record = $this->getNewItem("new-$className-$parentID", false);
        $this->treeAdmin->extend('updateDoAdd', $record, $form);
        $record->write();

        $this->treeAdmin->setRequest($this->getRequest());
        $this->treeAdmin->setCurrentPageID($record->ID);

        $session = $this->getRequest()->getSession();
        $session->set(
            "FormInfo.Form_EditForm.formError.message",
            _t('SilverStripe\\CMS\\Controllers\\CMSMain.PageAdded', 'Successfully created page')
        );
        $session->set("FormInfo.Form_EditForm.formError.type", 'good');

        return $this->redirect(Controller::join_links($this->treeAdmin->Link('edit'), 'show', $record->ID));
    }

    public function doCancel($data, $form)
    {
        return $this->redirect($this->treeAdmin->Link());
    }

    public function getBaseCSSClasses()
    {
        return $this->treeAdmin->BaseCSSClasses();
    }
}
