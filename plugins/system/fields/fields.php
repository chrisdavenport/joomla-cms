<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Fields
 *
 * @copyright   Copyright (C) 2005 - 2016 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\Registry\Registry;

JLoader::register('FieldsHelper', JPATH_ADMINISTRATOR . '/components/com_fields/helpers/fields.php');

/**
 * Fields Plugin
 *
 * @since  3.7
 */
class PlgSystemFields extends JPlugin
{
	/**
	 * Load the language file on instantiation.
	 *
	 * @var    boolean
	 * @since  3.7.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * The save event.
	 *
	 * @param   string    $context  The context
	 * @param   stdClass  $item     The item
	 * @param   boolean   $isNew    Is new
	 *
	 * @return  boolean
	 *
	 * @since   3.7.0
	 */
	public function onContentBeforeSave($context, $item, $isNew)
	{
		$parts = $this->getParts($context);

		if (!$parts)
		{
			return true;
		}

		$context = $parts[0] . '.' . $parts[1];

		// Loading the fields
		$fieldsObjects = FieldsHelper::getFields($context, $item);

		if (!$fieldsObjects)
		{
			return true;
		}

		$params = new Registry;

		// Load the item params from the request
		$data = JFactory::getApplication()->input->post->get('jform', array(), 'array');

		if (key_exists('params', $data))
		{
			$params->loadArray($data['params']);
		}

		// Load the params from the item itself
		if (isset($item->params))
		{
			$params->loadString($item->params);
		}

		$params = $params->toArray();

		// Create the new internal fields field
		$fields = array();

		foreach ($fieldsObjects as $field)
		{
			// Set the param on the fields variable
			$fields[$field->alias] = key_exists($field->alias, $params) ? $params[$field->alias] : array();

			// Remove it from the params array
			unset($params[$field->alias]);
		}

		$item->_fields = $fields;

		// Update the cleaned up params
		if (isset($item->params))
		{
			$item->params = json_encode($params);
		}

		return true;
	}

	/**
	 * The save event.
	 *
	 * @param   string    $context  The context
	 * @param   stdClass  $item     The item
	 * @param   boolean   $isNew    Is new
	 *
	 * @return  boolean
	 *
	 * @since   3.7.0
	 */
	public function onContentAfterSave($context, $item, $isNew)
	{
		$parts = $this->getParts($context);

		if (!$parts)
		{
			return true;
		}

		$context = $parts[0] . '.' . $parts[1];

		// Return if the item has no valid state
		$fields = null;

		if (isset($item->_fields))
		{
			$fields = $item->_fields;
		}

		if (!$fields)
		{
			return true;
		}

		// Loading the fields
		$fieldsObjects = FieldsHelper::getFields($context, $item);

		if (!$fieldsObjects)
		{
			return true;
		}

		// Loading the model
		$model = JModelLegacy::getInstance('Field', 'FieldsModel', array('ignore_request' => true));

		foreach ($fieldsObjects as $field)
		{
			// Only save the fields with the alias from the data
			if (!key_exists($field->alias, $fields))
			{
				continue;
			}

			$id = null;

			if (isset($item->id))
			{
				$id = $item->id;
			}

			if (!$id)
			{
				continue;
			}

			// Setting the value for the field and the item
			$model->setFieldValue($field->id, $context, $id, $fields[$field->alias]);
		}

		return true;
	}

	/**
	 * The save event.
	 *
	 * @param   array    $userData  The date
	 * @param   boolean  $isNew     Is new
	 * @param   boolean  $success   Is success
	 * @param   string   $msg       The message
	 *
	 * @return  boolean
	 *
	 * @since   3.7.0
	 */
	public function onUserAfterSave($userData, $isNew, $success, $msg)
	{
		// It is not possible to manipulate the user during save events
		// Check if data is valid or we are in a recursion
		if (!$userData['id'] || !$success)
		{
			return true;
		}

		$user = JFactory::getUser($userData['id']);
		$user->params = (string) $user->getParameters();

		// Trigger the events with a real user
		$this->onContentBeforeSave('com_users.user', $user, false);
		$this->onContentAfterSave('com_users.user', $user, false);

		// Save the user with the modified params
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true)->update('#__users')
			->set(array('params = ' . $db->quote($user->params)))
			->where('id = ' . $user->id);
		$db->setQuery($query);
		$db->execute();

		return true;
	}

	/**
	 * The delete event.
	 *
	 * @param   string    $context  The context
	 * @param   stdClass  $item     The item
	 *
	 * @return  boolean
	 *
	 * @since   3.7.0
	 */
	public function onContentAfterDelete($context, $item)
	{
		$parts = $this->getParts($context);

		if (!$parts)
		{
			return true;
		}

		$context = $parts[0] . '.' . $parts[1];

		JLoader::import('joomla.application.component.model');
		JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_fields/models', 'FieldsModel');

		$model = JModelLegacy::getInstance('Field', 'FieldsModel', array('ignore_request' => true));
		$model->cleanupValues($context, $item->id);

		return true;
	}

	/**
	 * The user delete event.
	 *
	 * @param   stdClass  $user    The context
	 * @param   boolean   $succes  Is success
	 * @param   string    $msg     The message
	 *
	 * @return  boolean
	 *
	 * @since   3.7.0
	 */
	public function onUserAfterDelete($user, $succes, $msg)
	{
		$item     = new stdClass;
		$item->id = $user['id'];

		return $this->onContentAfterDelete('com_users.user', $item);
	}

	/**
	 * The form event.
	 *
	 * @param   JForm     $form  The form
	 * @param   stdClass  $data  The data
	 *
	 * @return  boolean
	 *
	 * @since   3.7.0
	 */
	public function onContentPrepareForm(JForm $form, $data)
	{
		$context = $form->getName();
		$parts = $this->getParts($context);

		if (!$parts)
		{
			return true;
		}

		$input = JFactory::getApplication()->input;

		// If we are on the save command we need the actual data
		$jformData = $input->get('jform', array(), 'array');

		if ($jformData && !$data)
		{
			$data = $jformData;
		}

		if (is_array($data))
		{
			$data = (object) $data;
		}

		FieldsHelper::prepareForm($parts[0] . '.' . $parts[1], $form, $data);

		return true;
	}

	/**
	 * The prepare data event.
	 *
	 * @param   string    $context  The context
	 * @param   stdClass  $data     The data
	 *
	 * @return  void
	 *
	 * @since   3.7.0
	 */
	public function onContentPrepareData($context, $data)
	{
		$parts = $this->getParts($context);

		if (!$parts)
		{
			return;
		}

		if (isset($data->params) && $data->params instanceof Registry)
		{
			$data->params = $data->params->toArray();
		}
	}

	/**
	 * The display event.
	 *
	 * @param   string    $context     The context
	 * @param   stdClass  $item        The item
	 * @param   Registry  $params      The params
	 * @param   integer   $limitstart  The start
	 *
	 * @return  string
	 *
	 * @since   3.7.0
	 */
	public function onContentAfterTitle($context, $item, $params, $limitstart = 0)
	{
		return $this->display($context, $item, $params, 1);
	}

	/**
	 * The display event.
	 *
	 * @param   string    $context     The context
	 * @param   stdClass  $item        The item
	 * @param   Registry  $params      The params
	 * @param   integer   $limitstart  The start
	 *
	 * @return  string
	 *
	 * @since   3.7.0
	 */
	public function onContentBeforeDisplay($context, $item, $params, $limitstart = 0)
	{
		return $this->display($context, $item, $params, 2);
	}

	/**
	 * The display event.
	 *
	 * @param   string    $context     The context
	 * @param   stdClass  $item        The item
	 * @param   Registry  $params      The params
	 * @param   integer   $limitstart  The start
	 *
	 * @return  string
	 *
	 * @since   3.7.0
	 */
	public function onContentAfterDisplay($context, $item, $params, $limitstart = 0)
	{
		return $this->display($context, $item, $params, 3);
	}

	/**
	 * Performs the display event.
	 *
	 * @param   string    $context      The context
	 * @param   stdClass  $item         The item
	 * @param   Registry  $params       The params
	 * @param   integer   $displayType  The type
	 *
	 * @return  string
	 *
	 * @since   3.7.0
	 */
	private function display($context, $item, $params, $displayType)
	{
		$parts = $this->getParts($context);

		if (!$parts)
		{
			return '';
		}

		$context = $parts[0] . '.' . $parts[1];

		if (is_string($params) || !$params)
		{
			$params = new Registry($params);
		}

		$fields = FieldsHelper::getFields($context, $item, true);

		if ($fields)
		{
			foreach ($fields as $key => $field)
			{
				$fieldDisplayType = $field->params->get('display', '-1');

				if ($fieldDisplayType == '-1')
				{
					$fieldDisplayType = $this->params->get('display', '2');
				}

				if ($fieldDisplayType == $displayType)
				{
					continue;
				}

				unset($fields[$key]);
			}
		}

		if ($fields)
		{
			return FieldsHelper::render(
				$context,
				'fields.render',
				array(
					'item'            => $item,
					'context'         => $context,
					'fields'          => $fields,
					'container'       => $params->get('fields-container'),
					'container-class' => $params->get('fields-container-class'),
				)
			);
		}

		return '';
	}

	/**
	 * Performs the display event.
	 *
	 * @param   string    $context  The context
	 * @param   stdClass  $item     The item
	 *
	 * @return  void
	 *
	 * @since   3.7.0
	 */
	public function onContentPrepare($context, $item)
	{
		$parts = $this->getParts($context);

		if (!$parts)
		{
			return;
		}

		$fields = FieldsHelper::getFields($parts[0] . '.' . $parts[1], $item, true);

		// Adding the fields to the object
		$item->fields = array();

		foreach ($fields as $key => $field)
		{
			$item->fields[$field->id] = $field;
		}

		return;
	}

	/**
	 * The finder event.
	 *
	 * @param   FinderIndexerResult  &$item      The item to index as a FinderIndexerResult object.
	 * @param   string               $extension  The extension name.
	 *
	 * @return  boolean  True on success, false on failure.
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onPrepareFinderContent(FinderIndexerResult &$item, $extension = '')
	{
		$db = JFactory::getDbo();
		$context = $extension . '.' . strtolower($item->layout);

		// Create a dummy object with the required fields
		$tmp     = new stdClass;
		$tmp->id = $item->id;
		$tmp->catid = $item->catid;

		// Getting the fields for the constructed context
		$fields = FieldsHelper::getFields($context, $tmp, true);

		// No extra data to add to this content item.
		if (empty($fields))
		{
			return true;
		}

		$model = JModelLegacy::getInstance('Field', 'FieldsModel', array('ignore_request' => true));

		// Add the extra custom fields to the item to be indexed.
		foreach ($fields as $field)
		{
			// Get the raw value(s) of the field.
			$values = (array) $model->getFieldValue($field->id, $context, $item->id);

			if (empty($values))
			{
				continue;
			}

			switch ($field->type)
			{
				case 'calendar':
				case 'editor':
				case 'text':
				case 'textarea':
				case 'url':
					// Add an instruction to index the field value as HTML.
					$indexFieldName = 'jfield_' . $field->alias;
					$item->addInstruction(FinderIndexer::TEXT_CONTEXT, $indexFieldName);
					$item->{$indexFieldName} = implode(' ', $values);
					break;

				case 'checkboxes':
				case 'list':
				case 'radio':
					// Add enumerated fields to search taxonomies.
					$options = array();

					// Construct a map of possible field name-value pairs.
					foreach ($field->fieldparams->get('options', array()) as $option)
					{
						$options[$option->value] = $option->name;
					}

					// Add the actual field values to the search taxonomy.
					foreach ($values as $value)
					{
						$item->addTaxonomy($field->title, $options[$value]);
					}

					break;

				case 'colour':
				case 'color':
				case 'integer':
				case 'media':
				case 'tel':
				case 'timezone':
					// Array of simple values to add to the search taxonomy.
					foreach ($values as $value)
					{
						$item->addTaxonomy($field->title, $value);
					}

					break;

				case 'sql':
					// Get all the available options.
					$options = $db->setQuery($field->fieldparams->get('query'))->loadObjectList('value');

					// Nothing to index.
					if (empty($options))
					{
						continue;
					}

					// Add the actual field values to the search taxonomy.
					foreach ($values as $value)
					{
						if (!empty($options[$value]))
						{
							$item->addTaxonomy($field->title, $options[$value]->text);
						}
					}

					break;

				case 'user':
					// Get the names of one or more users from the users table by user id.
					$query = $db->getQuery(true)
						->select('name')
						->from('#__users')
						->where('id IN (' . implode(',', $values) . ')');

					// Add the actual field values to the search taxonomy.
					foreach ($db->setQuery($query)->loadColumn() as $value)
					{
						$item->addTaxonomy($field->title, $value);
					}

					break;

				case 'usergrouplist':
					// Get the names of one or more usergroups by usergroup id.
					$query = $db->getQuery(true)
						->select('title')
						->from('#__usergroups')
						->where('id IN (' . implode(',', $values) . ')');

					// Add the actual field values to the search taxonomy.
					foreach ($db->setQuery($query)->loadColumn() as $value)
					{
						$item->addTaxonomy($field->title, $value);
					}

					break;

				default:
					// Non-searchable fields.
					break;
			}
		}

		return true;
	}

	/**
	 * Returns the parts for the context.
	 *
	 * @param   string  $context  The context
	 *
	 * @return  array
	 *
	 * @since   3.7.0
	 */
	private function getParts($context)
	{
		// Some context mapping
		// @todo needs to be done in a general lookup table on some point
		$mapping = array(
				'com_users.registration' => 'com_users.user',
				'com_content.category'   => 'com_content.article',
		);

		if (key_exists($context, $mapping))
		{
			$context = $mapping[$context];
		}

		$parts = FieldsHelper::extract($context);

		if (!$parts)
		{
			return null;
		}

		if ($parts[1] === 'form')
		{
			// The context is not from a known one, we need to do a lookup
			// @todo use the api here.
			$db    = JFactory::getDbo();
			$query = $db->getQuery(true)
				->select('context')
				->from('#__fields')
				->where('context like ' . $db->quote($parts[0] . '.%'))
				->group(array('context'));
			$db->setQuery($query);
			$tmp = $db->loadObjectList();

			if (count($tmp) == 1)
			{
				$parts = FieldsHelper::extract($tmp[0]->context);

				if (count($parts) < 2)
				{
					return null;
				}
			}
		}

		return $parts;
	}
}
