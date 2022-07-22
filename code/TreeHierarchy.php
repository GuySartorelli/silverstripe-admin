<?php

namespace SilverStripe\Admin;

use SilverStripe\Control\Controller;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Config\Config;
use SilverStripe\Admin\ModelTreeAdmin;
use SilverStripe\ORM\Hierarchy\Hierarchy;

/**
 * DataObjects that use the TreeHierarchy extension can be be displayed and manipulated through a TreeModelAdmin. The most
 * obvious example of this is SiteTree.
 *
 * @property int $ParentID
 * @property DataObject|TreeHierarchy $owner
 * @method DataObject Parent()
 */
class TreeHierarchy extends Hierarchy
{
    /**
     * A list of classnames to exclude from display in the page tree views of the CMS,
     * unlike $hide_from_hierarchy above which effects both CMS and front end.
     * Especially useful for big sets of pages like listings
     * If you use this, and still need the classes to be editable
     * then add a model admin for the class
     * Note: Does not filter subclasses (non-inheriting)
     *
     * @var array
     * @config
     */
    private static $hide_from_cms_tree = [];

    /**
     * Checks if we're on a controller action where we should filter. ie. Are we loading a ModelTreeAdmin tree?
     *
     * @return bool
     */
    public function showingCMSTree()
    {
        if (!Controller::has_curr() || !class_exists(ModelTreeAdmin::class)) {
            return false;
        }
        $controller = Controller::curr();
        return $controller instanceof ModelTreeAdmin
            && in_array($controller->getAction(), ["treeview", "listview", "getsubtree"]);
    }

    public function stageChildren($showAll = false, $skipParentIDFilter = false)
    {
        $staged = parent::stageChildren($showAll, $skipParentIDFilter);
        $hideFromCMSTree = $this->owner->config()->get('hide_from_cms_tree');
        if ($hideFromCMSTree && $this->showingCMSTree()) {
            $staged = $staged->exclude('ClassName', $hideFromCMSTree);
        }
        return $staged;
    }

    public function liveChildren($showAll = false, $onlyDeletedFromStage = false)
    {
        $children = parent::liveChildren($showAll, $onlyDeletedFromStage);
        $hideFromCMSTree = $this->owner->config()->get('hide_from_cms_tree');
        if ($hideFromCMSTree && $this->showingCMSTree()) {
            $children = $children->exclude('ClassName', $hideFromCMSTree);
        }

        return $children;
    }
}
