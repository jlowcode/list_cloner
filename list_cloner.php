<?php

// No direct access
defined('_JEXEC') or die('Restricted access');

// Require the abstract plugin class
require_once COM_FABRIK_FRONTEND . '/models/plugin-form.php';
include_once (JPATH_BASE . '/plugins/fabrik_form/list_cloner/cloner/Cloner.class.php');


class PlgFabrik_FormList_cloner extends PlgFabrik_Form
{
    protected $user;
    protected $rowId;
    protected $permissionLevel;
    protected $listaPrincipal;
    protected $suggestId;
    protected $suggestElementId;
    protected $suggestFormId;
    protected $suggestCond;
    protected $suggestCloned = false;

    protected $tableNames = array();
    protected $elementsId = array();

    protected $clones_info = array();

    public function onAfterProcess()
    {
        //$formModel = $this->getModel();
        //$this->data = $this->getProcessData();
        //$formModel->formData = $this->data;



        $this->user = JFactory::getUser();

        $formModel = $this->getModel();
        $formData = $formModel->formData;
        $listName = $formModel->getTableName();
        $this->rowId = $formData[$listName . '___id'];
        $fields = $this->getFieldsAdministrator();
        $this->listaPrincipal = $fields->lista_principal;

        if ($fields->lista_principal) {
            $this->clone_process($fields->lista_principal);
        }

        $update = array();
        $update['id'] = $this->rowId;
        $update[$fields->link] = COM_FABRIK_LIVESITE . 'index.php?option=com_fabrik&view=list&listid=' . $this->clones_info[$fields->lista_principal]->listId;
        $update = (Object) $update;
        JFactory::getDbo()->updateObject($listName, $update, 'id');

        //$this->clone_process($fields->lista_principal, true);

        if ($this->suggestId) {
            $this->clone_process($this->suggestId, true);
            $db = JFactory::getDbo();
            $query = $db->getQuery(true);
            $query->select('params')->from("#__fabrik_elements")->where('id = ' . (int) $this->suggestElementId);
            $db->setQuery($query);
            $result = $db->loadResult();
            $result = json_decode($result);

            $cond = $this->suggestCond;

            if ($cond === 1) {
                $result->suggest_list_name_review = str_replace("formid={$this->suggestFormId}", "formid={$this->clones_info[$this->suggestId]->formId}", $result->suggest_list_name_review);
            }
            else if ($cond === 2) {
                $result->suggest_list_name_review = str_replace("/form/{$this->suggestFormId}", "/form/{$this->clones_info[$this->suggestId]->formId}", $result->suggest_list_name_review);
            }
            $result->suggest_db_name = $this->clones_info[$this->listaPrincipal]->db_table_name;

            $up = new stdClass();
            $up->id = $this->suggestElementId;
            $up->params = json_encode($result);
            $db->updateObject("#__fabrik_elements", $up, 'id');

            $query = $db->getQuery(true);
            $query->select('introduction')->from("#__fabrik_lists")->where('id = ' . (int) $this->clones_info[$this->suggestId]->listId);
            $db->setQuery($query);
            $introduction = $db->loadResult();

            $up_list = new stdClass();
            $up_list->id = $this->clones_info[$this->suggestId]->listId;
            if ($cond === 1) {
                $up->introduction = str_replace("formid={$this->suggestFormId}", "formid={$this->clones_info[$this->suggestId]->formId}", $introduction);
            }
            else if ($cond === 2) {
                $up->introduction = str_replace("/form/{$this->suggestFormId}", "/form/{$this->clones_info[$this->suggestId]->formId}", $introduction);
            }

            $db->updateObject("#__fabrik_lists", $up_list, 'id');
        }

        if ($fields->listas_auxiliares) {
            foreach ($fields->listas_auxiliares as $item) {
                $this->clone_process($item);
            }
        }

        $this->checkDatabaseJoins();


        foreach ($this->clones_info as $key => $item) {
            $e = $this->replaceElementsIdFormParams($key);
            $f = $this->replaceElementsIdListParams($key);
        }


        $app = $this->app;
        $app->getMessageQueue(true);
        $app->enqueueMessage('Lista clonada com sucesso!');
    }

    protected function setUserGroup($id_principal) {
        $db = JFactory::getDbo();

        $ug = new stdClass();
        $ug->id = 0;
        $ug->parent_id = 2;
        $ug->lft = 0;
        $ug->rgt = 0;
        $ug->title = $this->clones_info[$id_principal]->db_table_name;
        $db->insertObject("#__usergroups", $ug, 'id');

        $ug_id = $db->insertid();

        $ug_map = new stdClass();
        $ug_map->user_id = $this->user->id;
        $ug_map->group_id = $ug_id;
        $db->insertObject("#__user_usergroup_map", $ug_map, 'user_id, group_id');

        $level = new stdClass();
        $level->id = 0;
        $level->title = $this->clones_info[$id_principal]->db_table_name;
        $level->ordering = 0;
        $level->rules = "[6,2,8]";
        $db->insertObject("#__viewlevels", $level, 'id');

        $this->permissionLevel = $db->insertid();
    }

    protected function getFieldsAdministrator() {
        $params = $this->getParams();
        $formModel = $this->getModel();
        $elementModel = FabrikWorker::getPluginManager();

        $linkModel = $elementModel->getElementPlugin($params->get('list_link'))->element;
        $modeloModel = $elementModel->getElementPlugin($params->get('modelo'))->element;
        $tituloModel = $elementModel->getElementPlugin($params->get('titulo'))->element;

        $listaModelo = $params->get('list_cloner_lista_modelo');
        $elementNamelistaPrincipal = $params->get('list_cloner_element_name_lista_principal');
        $elementNameListasAuxiliares = $params->get('list_cloner_element_name_listas_auxiliares');

        $fields = new stdClass();
        $fields->link = $linkModel->name;
        $fields->modelo = $modeloModel->name;
        $fields->titulo = $formModel->formData[$tituloModel->name];

        $idModelo = $formModel->formData[$fields->modelo];
        if (is_array($idModelo)) {
            $idModelo = $idModelo[0];
        }
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select($elementNamelistaPrincipal)->from($listaModelo)->where('id = ' . (int) $idModelo);
        $db->setQuery($query);
        $fields->lista_principal = $db->loadResult();

        $query = $db->getQuery(true);
        $query->select($elementNameListasAuxiliares)->from("{$listaModelo}_repeat_{$elementNameListasAuxiliares}")->where('parent_id = ' . (int) $idModelo);
        $db->setQuery($query);
        $fields->listas_auxiliares = $db->loadColumn();

        return $fields;
    }

    protected function checkTableName ($name) {
        $db = JFactory::getDbo();

        $name = $this->user->id . '_' . $name;
        $continue = false;
        $flag = 1;
        while ($continue === false) {
            $db->setQuery("SHOW TABLES LIKE '{$name}_{$flag}'");
            $result = $db->loadResult();
            if ($result) {
                $flag++;
            } else {
                $continue = true;
            }
        }

        return $name . "_{$flag}";
    }

    protected function clone_process($listId, $is_suggest = false) {
        $listModel = JModelLegacy::getInstance('List', 'FabrikFEModel');
        $listModel->setId($listId);
        $formModel = $listModel->getFormModel();

        $info = new stdClass();
        $info->mappedGroups = array();
        $info->mappedElements = array();
        $info->elementsRepeat = array();
        $info->newListJoinsIds = array();
        $info->old_db_table_name = $formModel->getTableName();
        $info->db_table_name = $this->checkTableName($formModel->getTableName());

        if ($is_suggest) {
            $info->old_db_table_name = $this->clones_info[$this->listaPrincipal]->old_db_table_name;
            $info->db_table_name = $this->clones_info[$this->listaPrincipal]->db_table_name;
        }

        $this->clones_info[$listId] = $info;
        $this->tableNames[$info->old_db_table_name] = $info->db_table_name;

        if (!$this->permissionLevel) {
            $this->setUserGroup($listId);
        }

        $a = $this->cloneForm($formModel->getTable(), $listId, $is_suggest);
        $b = $this->cloneList($listModel->getTable(), $listId, $is_suggest);
        $c = $this->cloneGroupsAndElements($formModel->getGroupsHiarachy(), $listId);

        if (!$is_suggest) {
            $d = $this->createTables($listId);
        }
    }

    protected function cloneForm($data, $listId, $is_suggest = false) {
        $db = JFactory::getDbo();
        $fields_adm = $this->getFieldsAdministrator();

        //$data = $this->clones_info[$listId]->formData->getTable();
        $this->clones_info[$listId]->formParams = json_decode($data->params);

        $cloneData = new stdClass();
        $cloneData->id = 0;
        if ((($listId === $fields_adm->lista_principal) || ($is_suggest)) && ($fields_adm->titulo)) {
            $cloneData->label = $fields_adm->titulo;
        }
        else {
            $cloneData->label = $data->label;
        }
        if ($is_suggest) {
            $cloneData->label .= ' - Revisão';
        }
        $cloneData->record_in_database = $data->record_in_database;
        $cloneData->error = $data->error;
        $cloneData->intro = $data->intro;
        $cloneData->created = date('Y-m-d H:i:s');
        $cloneData->created_by = $this->user->id;
        $cloneData->created_by_alias = $this->user->username;
        $cloneData->modified = $data->modified;
        $cloneData->modified_by = $data->modified_by;
        $cloneData->checked_out = $data->checked_out;
        $cloneData->checked_out_time = $data->checked_out_time;
        $cloneData->publish_up = $data->publish_up;
        $cloneData->publish_down = $data->publish_down;
        $cloneData->reset_button_label = $data->reset_button_label;
        $cloneData->submit_button_label = $data->submit_button_label;
        $cloneData->form_template = $data->form_template;
        $cloneData->view_only_template = $data->view_only_template;
        $cloneData->published = $data->published;
        $cloneData->private = $data->private;
        $cloneData->params = $data->params;

        $insert = $db->insertObject('#__fabrik_forms', $cloneData, 'id');

        if (!$insert) {
            return false;
        }

        $this->clones_info[$listId]->formId = $db->insertid();

        return true;
    }

    protected function cloneList($data, $listId, $is_suggest = false) {
        $db = JFactory::getDbo();
        $fields_adm = $this->getFieldsAdministrator();
        //$data = $this->clones_info[$listId]->listModel->getTable();

        $this->clones_info[$listId]->listParams = json_decode($data->params);
        $this->clones_info[$listId]->orderByList = $data->order_by;

        $cloneData = new stdClass();
        $cloneData->id = 0;
        if ((($listId === $fields_adm->lista_principal) || ($is_suggest)) && ($fields_adm->titulo)) {
            $cloneData->label = $fields_adm->titulo;
        }
        else {
            $cloneData->label = $data->label;
        }
        if ($is_suggest) {
            $cloneData->label .= ' - Revisão';
        }
        $cloneData->introduction = $data->introduction;
        $cloneData->form_id = $this->clones_info[$listId]->formId;
        $cloneData->db_table_name = $this->clones_info[$listId]->db_table_name;
        $cloneData->db_primary_key = $this->clones_info[$listId]->db_table_name . '.id';
        $cloneData->auto_inc = $data->auto_inc;
        $cloneData->connection_id = $data->connection_id;
        $cloneData->created = date('Y-m-d H:i:s');
        $cloneData->created_by = $data->created_by;
        $cloneData->created_by_alias = $data->created_by_alias;
        $cloneData->modified = date('Y-m-d H:i:s');
        $cloneData->modified_by = $this->user->id;
        $cloneData->checked_out = $data->checked_out;
        $cloneData->checked_out_time = $data->checked_out_time;
        $cloneData->published = $data->published;
        $cloneData->publish_up = $data->publish_up;
        $cloneData->publish_down = $data->publish_down;
        $cloneData->access = $data->access;
        $cloneData->hits = $data->hits;
        $cloneData->rows_per_page = $data->rows_per_page;
        $cloneData->template = $data->template;
        //$cloneData->order_by = $data->order_by;
        $cloneData->order_dir = $data->order_dir;
        $cloneData->filter_action = $data->filter_action;
        $cloneData->group_by = $data->group_by;
        $cloneData->private = $data->private;
        $cloneData->params = $data->params;


        $insert = $db->insertObject('#__fabrik_lists', $cloneData, 'id');

        if (!$insert) {
            return false;
        }

        $this->clones_info[$listId]->listId = $db->insertid();

        return true;
    }

    protected function cloneGroupsAndElements($groups, $listId) {
        $db = JFactory::getDbo();
        $ordering = 1;

        //$groups = $this->clones_info[$listId]->formModel->getGroupsHiarachy();
        foreach ($groups as $groupModel) {

            $cloneData = $groupModel->getGroup()->getProperties();
            unset($cloneData['join_id']);
            $cloneData = (Object) $cloneData;
            $oldId = $cloneData->id;
            $cloneData->id = 0;
            $cloneData->created = date('Y-m-d H:i:s');
            $cloneData->created_by = $this->user->id;
            $cloneData->created_by_alias = $this->user->username;
            $insert1 = $db->insertObject('#__fabrik_groups', $cloneData, 'id');

            $obj = new stdClass();
            $obj->id = 0;
            $obj->form_id = $this->clones_info[$listId]->formId;
            $obj->group_id = $db->insertid();
            $obj->ordering = $ordering;
            $insert2 = $db->insertObject('#__fabrik_formgroup', $obj, 'id');

            if ($this->clones_info[$listId]->listParams->join_id) {
                foreach ($this->clones_info[$listId]->listParams->join_id as $key => $item) {
                    $query = $db->getQuery(true);
                    $query->select('group_id, params')->from("#__fabrik_joins")->where('id = ' . (int)$item);
                    $db->setQuery($query);
                    $result = $db->loadAssoc();
                    if ($result['group_id'] === $oldId) {
                        $this->cloneJoin($result['params'], $key, '', $listId, $obj->group_id, 'list_join');
                    }
                }
            }

            $this->clones_info[$listId]->mappedGroups[(string)$oldId] = $obj->group_id;

            $ordering++;

            $elementsModel = $groupModel->getMyElements();
            $cloneElementsFromThisGroup = $this->cloneElements($elementsModel, $obj->group_id, $listId);

            if ((!$insert1) || (!$insert2) || (!$cloneElementsFromThisGroup)) {
                return false;
            }
        }

        return true;
    }

    protected function cloneElements($elementsModel, $group_id, $listId) {
        $db = JFactory::getDbo();
        $fields_adm = $this->getFieldsAdministrator();
        //$ordering = 1;

        foreach ($elementsModel as $elementModel) {
            $cloneData = $elementModel->getElement()->getProperties();
            $cloneData = (Object) $cloneData;
            $oldId = $cloneData->id;
            $cloneData->id = 0;
            $cloneData->group_id = $group_id;
            $cloneData->created = date('Y-m-d H:i:s');
            $cloneData->created_by = $this->user->id;
            $cloneData->created_by_alias = $this->user->username;
            $cloneData->modified = date('Y-m-d H:i:s');
            //$cloneData->ordering = $ordering;

            $params = json_decode($cloneData->params);
            if ($cloneData->plugin === 'databasejoin') {
                $query = $db->getQuery(true);
                $query->select('id')->from("#__fabrik_lists")->where("db_table_name = '{$params->join_db_name}'");
                $db->setQuery($query);
                $res = $db->loadResult();
                if (in_array($res, $fields_adm->listas_auxiliares)) {
                    $new_table_name = $this->user->id . '_' . $params->join_db_name;
                    $continue = false;
                    $flag = 1;
                    while ($continue === false) {
                        $db->setQuery("SHOW TABLES LIKE '{$new_table_name}_{$flag}'");
                        $result = $db->loadResult();
                        if ($result) {
                            $flag++;
                        }
                        else {
                            $continue = true;
                        }
                    }
                    $new_table_name .= "_{$flag}";
                    $params->join_db_name = $new_table_name;
                }
            }
            else if ($cloneData->plugin === 'suggest') {
                $url = $params->suggest_list_name_review;
                $url_components = parse_url($url);
                parse_str($url_components['query'], $url_params);
                $cond = 0;

                if ($url_params['listid']) {
                    $reviewId = $url_params['listid'];
                    $cond = 1;
                }
                else {
                    $reviewId = explode('/', $url);
                    $reviewId = $reviewId[count($reviewId)-2];
                    $cond = 2;
                }

                if (($reviewId) && (!$this->suggestCloned)) {
                    $db = JFactory::getDbo();
                    $query = $db->getQuery(true);
                    $query->select('id')->from("#__fabrik_lists")->where("form_id = " . (int) $reviewId);
                    $db->setQuery($query);
                    $reviewListId = $db->loadResult();
                    $this->suggestFormId = $reviewId;
                    $this->suggestId = $reviewListId;
                    $params->suggest_db_name = $this->clones_info[$this->listaPrincipal]->db_table_name;
                    $this->suggestCond = $cond;
                    $this->suggestCloned = true;
                }
            }
            $cloneData->params = json_encode($params);

            $insert = $db->insertObject('#__fabrik_elements', $cloneData, 'id');
            $element_id = $db->insertid();
            $this->clones_info[$listId]->mappedElements[(string)$oldId] = $element_id;


            if ($cloneData->plugin === 'databasejoin') {
                $this->elementsId[] = $element_id;
                $dbJoinMulti = array('checkbox', 'multilist');
                if (in_array($params->database_join_display_type, $dbJoinMulti)) {
                    $this->clones_info[$listId]->elementsRepeat[] = $cloneData->name;
                    $this->cloneJoin($elementModel->getJoinModel()->getJoin(), $element_id, $cloneData->name, $listId, $group_id);
                } else {
                    $this->cloneJoin($elementModel->getJoinModel()->getJoin(), $element_id, $cloneData->name, $listId, $group_id, 'dbjoin_single');
                }
            }
            else if (($cloneData->plugin === 'fileupload') && ((bool) $params->ajax_upload)) {
                $this->clones_info[$listId]->elementsRepeat[] = $cloneData->name;
                $this->cloneJoin($elementModel->getJoinModel()->getJoin(), $element_id, $cloneData->name, $listId, $group_id);
            }
            else if ($cloneData->plugin === 'tags') {
                $this->clones_info[$listId]->elementsRepeat[] = $cloneData->name;
                $this->cloneJoin($elementModel->getJoinModel()->getJoin(), $element_id, $cloneData->name, $listId, $group_id);
            }
            else if ($cloneData->plugin === 'user') {
                $this->cloneJoin($elementModel->getJoinModel()->getJoin(), $element_id, $cloneData->name, $listId, $group_id, 'user');
            }
            else if ($cloneData->plugin === 'survey') {
                $this->clones_info[$listId]->elementsRepeat[] = $cloneData->name;
                $this->cloneJoin($elementModel->getJoinModel()->getJoin(), $element_id, $cloneData->name, $listId, $group_id);
            }
            else if ($cloneData->plugin === 'suggest') {
                $this->suggestElementId = $element_id;
            }

            if (!$insert) {
                return false;
            }

            //$ordering++;
        }

        return true;
    }

    protected function cloneJoin($data, $element_id, $element_name, $listId, $group_id, $type = '') {
        $db = JFactory::getDbo();

        $cloneData = new stdClass();
        $cloneData->id = 0;
        if ($type === 'user') {
            $cloneData->list_id = 0;
            $cloneData->element_id = $element_id;
            $cloneData->join_from_table = '';
            $cloneData->table_join = '#__users';
            $cloneData->table_key = $data->table_key;
            $cloneData->table_join_key = $data->table_join_key;
            $cloneData->join_type = $data->join_type;
            $cloneData->group_id = $group_id;

            $params = array();
            $params['join-label'] = 'name';
            $params['type'] = 'element';
            $params['pk'] = '`#__users`.`id`';
            $params = (Object) $params;
            $cloneData->params = json_encode($params);

        }
        else if ($type === 'list_join') {
            $cloneData->list_id = $this->clones_info[$listId]->listId;
            $cloneData->element_id = 0;
            $cloneData->join_from_table = $this->clones_info[$listId]->db_table_name;
            $cloneData->table_join = $this->clones_info[$listId]->listParams->table_join[$element_id];
            $cloneData->table_key = $this->clones_info[$listId]->listParams->table_key[$element_id];
            $cloneData->table_join_key = $this->clones_info[$listId]->listParams->table_join_key[$element_id];
            $cloneData->join_type = $this->clones_info[$listId]->listParams->join_type[$element_id];
            $cloneData->group_id = $group_id;
            $cloneData->params = $data;
        }
        else if ($type === 'dbjoin_single') {
            $cloneData->list_id = 0;
            $cloneData->element_id = $element_id;
            $cloneData->join_from_table = '';
            $cloneData->table_join = $data->table_join;
            $cloneData->table_key = $data->table_key;
            $cloneData->table_join_key = $data->table_join_key;
            $cloneData->join_type = $data->join_type;
            if ($data->group_id !== 0) {
                $cloneData->group_id = $group_id;
            }
            else {
                $cloneData->group_id = 0;
            }
            $params = json_decode($data->params);
            $cloneData->params = json_encode($params);
        }
        else {
            $cloneData->list_id = $this->clones_info[$listId]->listId;
            $cloneData->element_id = $element_id;
            $cloneData->join_from_table = $this->clones_info[$listId]->db_table_name;
            $cloneData->table_join = $this->clones_info[$listId]->db_table_name . '_repeat_' . $element_name;
            $cloneData->table_key = $data->table_key;
            $cloneData->table_join_key = $data->table_join_key;
            $cloneData->join_type = $data->join_type;
            if ($data->group_id !== 0) {
                $cloneData->group_id = $group_id;
            }
            else {
                $cloneData->group_id = 0;
            }
            $params = json_decode($data->params);
            $params->pk = str_replace($data->table_join, $cloneData->table_join, $params->pk);
            $cloneData->params = json_encode($params);
        }

        $insert = $db->insertObject('#__fabrik_joins', $cloneData, 'id');

        $this->clones_info[$listId]->newListJoinsIds[] = $db->insertid();

        return $insert;
    }

    protected function createTables($listId) {
        $db = JFactory::getDbo();
        $tableName = $this->clones_info[$listId]->db_table_name;
        $oldTableName = $this->clones_info[$listId]->old_db_table_name;

        $db->setQuery("CREATE TABLE $tableName LIKE $oldTableName");
        try
        {
            $db->execute();
        }
        catch (RuntimeException $e)
        {
            $err        = new stdClass;
            $err->error = $e->getMessage();
            echo json_encode($err);
            exit;
        }

        if (!$this->createTablesRepeat($listId)) {
            return false;
        }

        return true;
    }

    protected function createTablesRepeat($listId) {
        $db = JFactory::getDbo();
        $tableName = $this->clones_info[$listId]->db_table_name;
        $oldTableName = $this->clones_info[$listId]->old_db_table_name;
        $elementsRepeat = $this->clones_info[$listId]->elementsRepeat;

        foreach ($elementsRepeat as $element) {
            $cloneTable = $tableName . '_repeat_' . $element;
            $table = $oldTableName . '_repeat_' . $element;

            $db->setQuery("CREATE TABLE $cloneTable LIKE $table");
            try
            {
                $db->execute();
            }
            catch (RuntimeException $e)
            {
                $err        = new stdClass;
                $err->error = $e->getMessage();
                echo json_encode($err);
                exit;
            }
        }

        return true;
    }

    protected function replaceElementsIdFormParams($listId) {
        $formParams = (array) $this->clones_info[$listId]->formParams;
        $mappedElements = $this->clones_info[$listId]->mappedElements;
        $mappedGroups = $this->clones_info[$listId]->mappedGroups;

        //Registration_certificate
        if (key_exists('arquivo', $formParams)) {
            $formParams['arquivo'] = (string) $mappedElements[$formParams['arquivo']];
            $formParams['hash'] = (string) $mappedElements[$formParams['hash']];
            $formParams['certificado'] = (string) $mappedElements[$formParams['certificado']];
            $formParams['grupo_pdf'] = (string) $mappedGroups[$formParams['grupo_pdf']];
        }

        //Online_contracts
        if (key_exists('element_dbjoin', $formParams)) {
            $formParams['element_dbjoin'] = (string) $mappedElements[$formParams['element_dbjoin']];
            $formParams['groupid_modelo'] = (string) $mappedGroups[$formParams['groupid_modelo']];
            $formParams['groupid_form'] = (string) $mappedGroups[$formParams['groupid_form']];
        }

        //Textextract
        if (key_exists('textextract_file_from', $formParams)) {
            $textextract_file_from = (array) $formParams['textextract_file_from'];
            $newTextExtract = array();
            foreach ($textextract_file_from as $key => $item) {
                $newTextExtract[$key] = (string) $mappedElements[$item];
            }
            if (is_object($formParams['textextract_file_from'])) {
                $formParams['textextract_file_from'] = (Object) $newTextExtract;
            }
            else {
                $formParams['textextract_file_from'] = $newTextExtract;
            }
            $formParams['textextract_destination'] = (string) $mappedElements[$formParams['textextract_destination']];
        }

        //Url_capture
        if (key_exists('campo_field', $formParams)) {
            $formParams['campo_field'] = (string) $mappedElements[$formParams['campo_field']];
        }

        //Review
        if (key_exists('review_id_master', $formParams)) {
            $formParams['review_id_master'] = (string) $mappedElements[$formParams['review_id_master']];
            $formParams['review_status'] = (string) $mappedElements[$formParams['review_status']];
        }

        //Upsert
        if (key_exists('upsert_insert_only', $formParams)) {
            $keys = array('primary_key', 'upsert_fields', 'upsert_key');
            foreach ($this->clones_info as $id_list => $item) {
                $old_id = (array) $formParams['table'];
                if (in_array($id_list, $old_id)) {
                    $old_id[1] = $item->listId;
                }
                $formParams['table'] = (Object) $old_id;
                foreach ($keys as $key) {
                    $old = json_encode($formParams[$key]);
                    if (strpos($old, $item->old_db_table_name) !== false) {
                        $new = str_replace($item->old_db_table_name, $item->db_table_name, $old);
                        $formParams[$key] = json_decode($new);
                    }
                }
            }
        }

        //Recursive_tree
        if (key_exists('list_elemento_origem', $formParams)) {
            $data = json_decode($formParams['list_elemento_origem']);
            $newData = array();
            foreach ($data->elemento_origem as $item) {
                $newData[] = (string) $mappedElements[$item];
            }
            $data->elemento_origem = $newData;
            $formParams['list_elemento_origem'] = json_encode($data);
            $data2 = json_decode($formParams['list_elemento_destino']);
            $newData2 = array();
            foreach ($data2->elemento_destino as $item) {
                $newData2[] = (string) $mappedElements[$item];
            }
            $data2->elemento_destino = $newData2;
            $formParams['list_elemento_destino'] = json_encode($data2);

            //$formParams['elemento_origem'] = (string) $mappedElements[$formParams['elemento_origem']];
            //$formParams['elemento_destino'] = (string) $mappedElements[$formParams['elemento_destino']];
        }

        //Metadata_Extract
        if (key_exists('thumb', $formParams)) {
            $keys = array('thumb', 'link', 'title', 'description', 'subject', 'creator', 'date', 'format', 'coverage', 'publisher', 'identifier', 'language', 'type', 'contributor', 'relation', 'rights', 'source');
            foreach ($keys as $key) {
                $formParams[$key] = (string) $mappedElements[$formParams[$key]];
            }
        }

        $formParams = (Object) $formParams;

        $obj = new stdClass();
        $obj->id = $this->clones_info[$listId]->formId;
        $obj->params = json_encode($formParams);
        $update = JFactory::getDbo()->updateObject('#__fabrik_forms', $obj, 'id');

        if (!$update) {
            return false;
        }

        return true;
    }

    protected function replaceElementsIdListParams($listId) {
        $listParams = (array) $this->clones_info[$listId]->listParams;
        $mappedElements = $this->clones_info[$listId]->mappedElements;
        $mappedGroups = $this->clones_info[$listId]->mappedGroups;

        //Access
        $listParams['allow_edit_details'] = (string) $this->permissionLevel;
        $listParams['allow_delete'] = (string) $this->permissionLevel;

        //List Search Elements
        $data = json_decode($listParams['list_search_elements']);
        $newData = array();
        foreach ($data->search_elements as $item) {
            $newData[] = (string) $mappedElements[$item];
        }
        $data->search_elements = $newData;
        $listParams['list_search_elements'] = json_encode($data);

        //Thumbnail
        if (key_exists('thumbnail', $listParams)) {
            $listParams['thumbnail'] = (string) $mappedElements[$listParams['thumbnail']];
        }

        //Titulo
        if (key_exists('titulo', $listParams)) {
            $listParams['titulo'] = (string) $mappedElements[$listParams['titulo']];
        }

        //Feed title
        if (key_exists('feed_title', $listParams)) {
            $listParams['feed_title'] = (string) $mappedElements[$listParams['feed_title']];
        }

        //Feed date
        if (key_exists('feed_date', $listParams)) {
            $listParams['feed_date'] = (string) $mappedElements[$listParams['feed_date']];
        }

        //Feed image
        if (key_exists('feed_image_src', $listParams)) {
            $listParams['feed_image_src'] = (string) $mappedElements[$listParams['feed_image_src']];
        }

        //Open Archive Elements
        if ($listParams['open_archive_elements']) {
            $data2 = json_decode($listParams['open_archive_elements']);
            $newData2 = array();
            foreach ($data2->dublin_core_element as $item) {
                $newData2[] = (string) $mappedElements[$item];
            }
            $data2->dublin_core_element = $newData2;
            $listParams['open_archive_elements'] = json_encode($data2);
        }

        //Search Title
        if (key_exists('search_title', $listParams)) {
            $listParams['search_title'] = (string) $mappedElements[$listParams['search_title']];
        }

        //Search Description
        if (key_exists('search_description', $listParams)) {
            $listParams['search_description'] = (string) $mappedElements[$listParams['search_description']];
        }

        //Search Date
        if (key_exists('search_date', $listParams)) {
            $listParams['search_date'] = (string) $mappedElements[$listParams['search_date']];
        }

        //Filter fields
        if ($listParams['filter-fields']) {
            $filter_fields = $listParams['filter-fields'];
            $newFields = array();
            foreach ($filter_fields as $field) {
                $newFields[] = $this->user->id . '_' . $field;
            }
            $listParams['filter-fields'] = $newFields;
        }

        //Allow edit details
        if ($listParams['allow_edit_details2']) {
            $listParams['allow_edit_details2'] = $this->user->id . '_' . $listParams['allow_edit_details2'];
        }

        //Allow delete
        if ($listParams['allow_delete2']) {
            $listParams['allow_delete2'] = $this->user->id . '_' . $listParams['allow_delete2'];
        }

        //Order by
        $order_by = json_decode($this->clones_info[$listId]->orderByList);
        $newOrder_by = array();
        foreach ($order_by as $item) {
            $newOrder_by[] = (string) $mappedElements[$item];
        }

        //List Joins
        $listParams['join_id'] = $this->clones_info[$listId]->newListJoinsIds;
        if ($listParams['join_from_table']) {
            foreach ($listParams['join_from_table'] as $key => $item) {
                if ($item === $this->clones_info[$listId]->old_db_table_name) {
                    $listParams['join_from_table'][$key] = $this->clones_info[$listId]->db_table_name;
                }
            }
        }

        $listParams = (Object) $listParams;

        $obj = new stdClass();
        $obj->id = $this->clones_info[$listId]->listId;
        $obj->order_by = JFactory::getDbo()->escape(json_encode($newOrder_by));
        $obj->params = json_encode($listParams);
        $update = JFactory::getDbo()->updateObject('#__fabrik_lists', $obj, 'id');

        if (!$update) {
            return false;
        }

        return true;
    }

    public function checkDatabaseJoins() {
        $db = JFactory::getDbo();
        foreach($this->elementsId as $elementId) {
            $query = $db->getQuery(true);
            $query->select('params')->from('#__fabrik_elements')->where("id = '{$elementId}'");
            $db->setQuery($query);
            $result = $db->loadResult();
            $params = json_decode($result);
            if (array_key_exists($params->join_db_name, $this->tableNames)) {
                $params->join_db_name = $this->tableNames[$params->join_db_name];
                $params = json_encode($params);
                $update = new stdClass();
                $update->id = $elementId;
                $update->params = $params;
                $db->updateObject('#__fabrik_elements', $update, 'id');
            }
        }
    }
}