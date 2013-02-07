<?php
/**
 * Allows inline editing of grid field records without having to load a separate
 * edit interface.
 *
 * The form fields used can be configured by setting the value in {@link setDisplayFields()} to one
 * of the following forms:
 *   - A Closure which returns the field instance.
 *   - An array with a `callback` key pointing to a function which returns the field.
 *   - An array with a `field` key->response specifying the field class to use.
 */
class GridFieldEditableColumns extends GridFieldDataColumns implements
	GridField_HTMLProvider,
	GridField_SaveHandler,
	GridField_URLHandler {

	/**
	 * @var Form[]
	 */
	protected $forms = array();

	public function getColumnContent($grid, $record, $col) {
		if(!$record->canEdit()) {
			return parent::getColumnContent($grid, $record, $col);
		}

		$fields = $this->getForm($grid, $record)->Fields();
		$value  = $grid->getDataFieldValue($record, $col);
		$field  = clone $fields->fieldByName($col);

		if(!$field) {
			throw new Exception("Could not find the field '$col'");
		}

		if(array_key_exists($col, $this->fieldCasting)) {
			$value = $grid->getCastedValue($value, $this->fieldCasting[$col]);
		}

		$value = $this->formatValue($grid, $record, $col, $value);

		$field->setName($this->getFieldName($field->getName(), $grid, $record));
		$field->setValue($value);

		return $field->Field();
	}

	public function getHTMLFragments($grid) {
		$grid->addExtraClass('ss-gridfield-editable');
	}

	public function handleSave(GridField $grid, DataObjectInterface $record) {
		$value = $grid->Value();

		if(!isset($value[__CLASS__]) || !is_array($value[__CLASS__])) {
			return;
		}

		$form = $this->getForm($grid, $record);

		foreach($value[__CLASS__] as $id => $fields) {
			if(!is_numeric($id) || !is_array($fields)) {
				continue;
			}

			$item = $grid->getList()->byID($id);

			if(!$item || !$item->canEdit()) {
				continue;
			}

			$form->loadDataFrom($fields, Form::MERGE_CLEAR_MISSING);
			$form->saveInto($item);

			$item->write();
		}
	}

	public function handleForm(GridField $grid, $request) {
		$id   = $request->param('ID');
		$list = $grid->getList();

		if(!ctype_digit($id)) {
			throw new SS_HTTPResponse_Exception(null, 400);
		}

		if(!$record = $list->byID($id)) {
			throw new SS_HTTPResponse_Exception(null, 404);
		}

		$form = $this->getForm($grid, $record);

		foreach($form->Fields() as $field) {
			$field->setName($this->getFieldName($field->getName(), $grid, $record));
		}

		return $form;
	}

	public function getURLHandlers($grid) {
		return array(
			'editable/form/$ID' => 'handleForm'
		);
	}

	/**
	 * Gets the field list for a record.
	 *
	 * @param GridField $grid
	 * @param DataObjectInterface $record
	 * @return FieldList
	 */
	protected function getFields(GridField $grid, DataObjectInterface $record) {
		$cols   = $this->getDisplayFields($grid);
		$fields = new FieldList();
		$class  = $grid->getList()->dataClass();

		foreach($cols as $col => $info) {
			$field = null;

			if($info instanceof Closure) {
				$field = call_user_func($info, $record, $col, $grid);
			} elseif(is_array($info)) {
				if(isset($info['callback'])) {
					$field = call_user_func($info['callback'], $record, $col, $grid);
				} elseif(isset($info['field'])) {
					$field = new $info['field']($col);
				}

				if(!$field instanceof FormField) {
					throw new Exception(sprintf(
						'The field for column "%s" is not a valid form field',
						$col
					));
				}
			}

			if(!$field) {
				if($obj = singleton($class)->dbObject($col)) {
					$field = $obj->scaffoldFormField();
				} else {
					$field = new ReadonlyField($col);
				}
			}

			if(!$field instanceof FormField) {
				throw new Exception(sprintf(
					'Invalid form field instance for column "%s"', $col
				));
			}

			$fields->push($field);
		}

		return $fields;
	}

	/**
	 * Gets the form instance for a record.
	 *
	 * @param GridField $grid
	 * @param DataObjectInterface $record
	 * @return Form
	 */
	protected function getForm(GridField $grid, DataObjectInterface $record) {
		$fields = $this->getFields($grid, $record);

		$form = new Form($this, null, $fields, new FieldList());
		$form->loadDataFrom($record);

		$form->setFormAction(Controller::join_links(
			$grid->Link(), 'editable/form', $record->ID
		));

		return $form;
	}

	protected function getFieldName($name,  GridField $grid, DataObjectInterface $record) {
		return sprintf(
			'%s[%s][%s][%s]', $grid->getName(), __CLASS__, $record->ID, $name
		);
	}

}