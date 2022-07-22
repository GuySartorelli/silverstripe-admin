<?php

namespace SilverStripe\Admin\ModelTreeAdmin;

use Page;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Admin\ModelTreeAdmin;
use SilverStripe\CampaignAdmin\AddToCampaignHandler;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\ArrayLib;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\ValidationResult;

/**
 * @package cms
 */
class EditController extends Controller
{
    private static $allowed_actions = [
        'show',
        'AddToCampaignForm',
    ];

    protected ModelTreeAdmin $treeAdmin;

    public function __construct(ModelTreeAdmin $treeAdmin)
    {
        $this->treeAdmin = $treeAdmin;
        parent::__construct();
    }

    /**
     * @param HTTPRequest $request
     * @return HTTPResponse
     * @throws HTTPResponse_Exception
     */
    public function show($request)
    {
        if ($request->param('ID')) {
            $this->treeAdmin->setCurrentPageID($request->param('ID'));
        }
        return $this->treeAdmin->getResponseNegotiator()->respond($request);
    }

    public function getClientConfig()
    {
        return ArrayLib::array_merge_recursive(parent::getClientConfig(), [
            'form' => [
                'AddToCampaignForm' => [
                    'schemaUrl' => $this->Link('schema/AddToCampaignForm'),
                ],
                'editorInternalLink' => [
                    'schemaUrl' => LeftAndMain::singleton()
                        ->Link('methodSchema/Modals/editorInternalLink'),
                ],
                'editorAnchorLink' => [
                    'schemaUrl' => LeftAndMain::singleton()
                        ->Link('methodSchema/Modals/editorAnchorLink/:pageid'),
                ],
            ],
        ]);
    }

    /**
     * Action handler for adding pages to a campaign
     *
     * @param array $data
     * @param Form $form
     * @return DBHTMLText|HTTPResponse
     */
    public function addtocampaign($data, $form)
    {
        $id = $data['ID'];
        $record = \Page::get()->byID($id);

        $handler = AddToCampaignHandler::create($this, $record);
        $results = $handler->addToCampaign($record, $data);
        if (is_null($results)) {
            return null;
        }

        if ($this->getSchemaRequested()) {
            // Send extra "message" data with schema response
            $extraData = ['message' => $results];
            $schemaId = Controller::join_links($this->Link('schema/AddToCampaignForm'), $id);
            return $this->getSchemaResponse($schemaId, $form, null, $extraData);
        }

        return $results;
    }

    /**
     * Url handler for add to campaign form
     *
     * @param HTTPRequest $request
     * @return Form
     */
    public function AddToCampaignForm($request)
    {
        // Get ID either from posted back value, or url parameter
        $id = $request->param('ID') ?: $request->postVar('ID');
        return $this->getAddToCampaignForm($id);
    }

    /**
     * @param int $id
     * @return Form
     */
    public function getAddToCampaignForm($id)
    {
        // Get record-specific fields
        $record = SiteTree::get()->byID($id);

        if (!$record) {
            $this->httpError(404, _t(
                __CLASS__ . '.ErrorNotFound',
                'That {Type} couldn\'t be found',
                '',
                ['Type' => Page::singleton()->i18n_singular_name()]
            ));
            return null;
        }
        if (!$record->canView()) {
            $this->httpError(403, _t(
                __CLASS__.'.ErrorItemPermissionDenied',
                'It seems you don\'t have the necessary permissions to add {ObjectTitle} to a campaign',
                '',
                ['ObjectTitle' => Page::singleton()->i18n_singular_name()]
            ));
            return null;
        }

        $handler = AddToCampaignHandler::create($this, $record);
        $form = $handler->Form($record);

        $form->setValidationResponseCallback(function (ValidationResult $errors) use ($form, $id) {
            $schemaId = Controller::join_links($this->Link('schema/AddToCampaignForm'), $id);
            return $this->getSchemaResponse($schemaId, $form, $errors);
        });

        return $form;
    }
}
