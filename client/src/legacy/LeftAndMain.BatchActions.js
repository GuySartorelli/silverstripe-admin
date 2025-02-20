/**
 * File: LeftAndMain.BatchActions.js
 */
import $ from 'jquery';
import i18n from 'i18n';

$.entwine('ss.tree', function($){

  /**
   * Class: #Form_BatchActionsForm
   *
   * Batch actions which take a bunch of selected pages,
   * usually from the CMS tree implementation, and perform serverside
   * callbacks on the whole set. We make the tree selectable when the jQuery.UI tab
   * enclosing this form is opened.
   *
   * Events:
   *  register - Called before an action is added.
   *  unregister - Called before an action is removed.
   */
  $('#Form_BatchActionsForm').entwine({

    /**
     * Variable: Actions
     * (Array) Stores all actions that can be performed on the collected IDs as
     * function closures. This might trigger filtering of the selected IDs,
     * a confirmation message, etc.
     */
    Actions: [],

    getTree: function() {
      return $('.cms-tree');
    },

    fromTree: {
      oncheck_node: function(e, data){
        this.serializeFromTree();
      },
      onuncheck_node: function(e, data){
        this.serializeFromTree();
      }
    },

    onmatch: function () {
      var self = this;

      self.getTree()
      .bind('load_node.jstree', function (e, data) {
        self.refreshSelected();
      });
    },

    onunmatch: function () {
      var self = this;

      self.getTree()
        .unbind('load_node.jstree');
    },

    /**
     * @func registerDefault
     * @desc Register default bulk confirmation dialogs
     */
    registerDefault: function() {
      // Publish selected pages action
      this.register('publish', function(ids) {
        var confirmed = confirm(
          i18n.inject(
            i18n._t(
              "Admin.BATCH_PUBLISH_PROMPT",
              "You have {num} page(s) selected.\n\nDo you really want to publish?"
            ),
            {'num': ids.length}
          )
        );
        return (confirmed) ? ids : false;
      });

      // Unpublish selected pages action
      this.register('unpublish', function(ids) {
        var confirmed = confirm(
          i18n.inject(
            i18n._t(
              "Admin.BATCH_UNPUBLISH_PROMPT",
              "You have {num} page(s) selected.\n\nDo you really want to unpublish"
            ),
            {'num': ids.length}
          )
        );
        return (confirmed) ? ids : false;
      });

      // Delete and archive selected pages action
      this.register('delete', function(ids) {
        var confirmed = confirm(
          i18n.inject(
            i18n._t(
              "Admin.BATCH_DELETE_PROMPT",
              "You have {num} page(s) selected.\n\nAre you sure you want to delete these pages?\n\nThese pages and all of their children pages will be deleted and sent to the archive."
            ),
            {'num': ids.length}
          )
        );
        return (confirmed) ? ids : false;
      });

      // Restore selected archived pages
      this.register('restore', function(ids) {
        var confirmed = confirm(
          i18n.inject(
            i18n._t(
              "Admin.BATCH_RESTORE_PROMPT",
              "You have {num} page(s) selected.\n\nDo you really want to restore to stage?\n\nChildren of archived pages will be restored to the root level, unless those pages are also being restored."
            ),
            {'num': ids.length}
          )
        );
        return (confirmed) ? ids : false;
      });
    },

    onadd: function() {
      this.registerDefault();
      this._super();
    },

    /**
     * @func register
     * @param {string} type
     * @param {function} callback
     */
    register: function(type, callback) {
      this.trigger('register', {type: type, callback: callback});
      var actions = this.getActions();
      actions[type] = callback;
      this.setActions(actions);
    },

    /**
     * @func unregister
     * @param {string} type
     * @desc Remove an existing action.
     */
    unregister: function(type) {
      this.trigger('unregister', {type: type});

      var actions = this.getActions();
      if(actions[type]) delete actions[type];
      this.setActions(actions);
    },

    /**
     * @func refreshSelected
     * @param {object} rootNode
     * @desc Ajax callbacks determine which pages is selectable in a certain batch action.
     */
    refreshSelected : function(rootNode) {
      var self = this,
        st = this.getTree(),
        ids = this.getIDs(),
        allIds = [],
        viewMode = $('.cms-content-batchactions-button'),
        actionUrl = this.find(':input[name=Action]').val();

      // Default to refreshing the entire tree
      if(rootNode == null) rootNode = st;

      for(var idx in ids) {
        $($(st).getNodeByID(idx)).addClass('selected').attr('selected', 'selected');
      }

      // If no action is selected, enable all nodes
      if(!actionUrl || actionUrl == -1 || !viewMode.hasClass('active')) {
        $(rootNode).find('li').each(function() {
          $(this).setEnabled(true);
        });
        return;
      }

      // Disable the nodes while the ajax request is being processed
      $(rootNode).find('li').each(function() {
        allIds.push($(this).data('id'));
        $(this).addClass('treeloading').setEnabled(false);
      });

      // Post to the server to ask which pages can have this batch action applied
      // Retain existing query parameters in URL before appending path
      var actionUrlParts = $.path.parseUrl(actionUrl);
      var applicablePagesUrl = actionUrlParts.hrefNoSearch + '/applicablepages/';
      applicablePagesUrl = $.path.addSearchParams(applicablePagesUrl, actionUrlParts.search);
      applicablePagesUrl = $.path.addSearchParams(applicablePagesUrl, {csvIDs: allIds.join(',')});
      jQuery.getJSON(applicablePagesUrl, function(applicableIDs) {
        // Set a CSS class on each tree node indicating which can be batch-actioned and which can't
        jQuery(rootNode).find('li').each(function() {
          $(this).removeClass('treeloading');

          var id = $(this).data('id');
          if(id == 0 || $.inArray(id, applicableIDs) >= 0) {
            $(this).setEnabled(true);
          } else {
            // De-select the node if it's non-applicable
            $(this).removeClass('selected').setEnabled(false);
            $(this).prop('selected', false);
          }
        });

        self.serializeFromTree();
      });
    },

    /**
     * @func serializeFromTree
     * @return {boolean}
     */
    serializeFromTree: function() {
      var tree = this.getTree(), ids = tree.getSelectedIDs();

      // write IDs to the hidden field
      this.setIDs(ids);

      return true;
    },

    /**
     * @func setIDS
     * @param {array} ids
     */
    setIDs: function(ids) {
      this.find(':input[name=csvIDs]').val(ids ? ids.join(',') : null);
    },

    /**
     * @func getIDS
     * @return {array}
     */
    getIDs: function() {
      // Map empty value to empty array
      var value = this.find(':input[name=csvIDs]').val();
      return value
        ? value.split(',')
        : [];
    },

    onsubmit: function(e) {
      var self = this,
        ids = this.getIDs(),
        tree = this.getTree(),
        actions = this.getActions();

      // if no nodes are selected, return with an error
      if(!ids || !ids.length) {
        alert(i18n._t('Admin.SELECTONEPAGE', 'Please select at least one page'));
        e.preventDefault();
        return false;
      }

      // apply callback, which might modify the IDs
      var actionURL = this.find(':input[name=Action]').val();
      if (!actionURL) {
        e.preventDefault();
        return false;
      }

      // Validate action
      var type = actionURL.split('/').filter(n => !!n).pop();
      if(actions[type]) {
        ids = actions[type].apply(this, [ids]);
      }

      // Discontinue processing if there are no further items
      if(!ids || !ids.length) {
        e.preventDefault();
        return false;
      }

      // write (possibly modified) IDs back into to the hidden field
      this.setIDs(ids);

      // Reset failure states
      tree.find('li').removeClass('failed');

      var button = this.find(':submit:first');
      button.addClass('loading');

      jQuery.ajax({
        // don't use original form url
        url: actionURL,
        type: 'POST',
        data: this.serializeArray(),
        complete: function(xmlhttp, status) {
          button.removeClass('loading');

          // Refresh the tree.
          // Makes sure all nodes have the correct CSS classes applied.
          tree.jstree('refresh', -1);
          self.setIDs([]);

          // Reset action
          self.find(':input[name=Action]').val('').change();

          // status message (decode into UTF-8, HTTP headers don't allow multibyte)
          var msg = xmlhttp.getResponseHeader('X-Status');
          if(msg) statusMessage(decodeURIComponent(msg), (status === 'success') ? 'success' : 'error');
        },
        success: function(data, status) {
          var id, node;

          if(data.modified) {
            var modifiedNodes = [];
            for(id in data.modified) {
              node = tree.getNodeByID(id);
              tree.jstree('set_text', node, data.modified[id]['TreeTitle']);
              modifiedNodes.push(node);
            }
            $(modifiedNodes).effect('highlight');
          }
          if(data.deleted) {
            for(id in data.deleted) {
              node = tree.getNodeByID(id);
              if(node.length)  tree.jstree('delete_node', node);
            }
          }
          if(data.error) {
            for(id in data.error) {
              node = tree.getNodeByID(id);
              $(node).addClass('failed');
            }
          }
        },
        dataType: 'json'
      });

      // Never process this action; Only invoke via ajax
      e.preventDefault();
      return false;
    }

  });

  $('.cms-content-batchactions-button').entwine({
    onmatch: function () {
      this._super();
      this.updateTree();
    },
    onunmatch: function () {
      this._super();
    },
    onclick: function (e) {
      this.updateTree();
    },
    updateTree: function () {
      var tree = $('.cms-tree'),
        form = $('#Form_BatchActionsForm');

      this._super();

      if(this.data('active')) {
        tree.addClass('multiple');
        tree.removeClass('draggable');
        form.serializeFromTree();
      } else {
        tree.removeClass('multiple');
        tree.addClass('draggable');
      }

      $('#Form_BatchActionsForm').refreshSelected();
    }
  });

  /**
   * Class: #Form_BatchActionsForm :select[name=Action]
   */
  $('#Form_BatchActionsForm select[name=Action]').entwine({
    onchange: function(e) {
      var form = $(e.target.form),
        btn = form.find(':submit'),
        selected = $(e.target).val(),
        actionUrlParts = selected.split('/'),
        actionName = actionUrlParts[actionUrlParts.length - 1];

      // Refresh selected / enabled nodes
      $('#Form_BatchActionsForm').refreshSelected();

      // Process action parameter fields
      var parameterFields = $('#BatchActionParameters_' + actionName);
      if (parameterFields.length) {
        // Reset fields to default values before displaying them 
        parameterFields.find(':input').each(function () {
          var input = $(this)[0];
          if (input.tagName === 'SELECT') {
            input.selectedIndex = -1;
            $(this).trigger('chosen:updated');
          } else if (input.type === 'checkbox') {
            input.checked = input.defaultChecked;
          } else {
            input.value = input.defaultValue;
          }
        });

        // Hide / display action parameter fields
        parameterFields.siblings().hide();
        parameterFields.show();
        $('#BatchActionParameters').slideDown();
      } else {
        $('#BatchActionParameters').slideUp();
      }

      // TODO Should work by triggering change() along, but doesn't - entwine event bubbling?
      this.trigger('chosen:updated');

      this._super(e);
    }
  });
});
