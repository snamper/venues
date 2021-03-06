<?php defined('SYSPATH') or die('No direct access allowed.');
/**
 * Anqh Venues controller
 *
 * @package    Venues
 * @author     Antti Qvickström
 * @copyright  (c) 2010-2011 Antti Qvickström
 * @license    http://www.opensource.org/licenses/mit-license.php MIT license
 */
class Anqh_Controller_Venues extends Controller_Template {

	/**
	 * Construct controller
	 */
	public function before() {
		parent::before();

		$this->page_title = __('Venues');
	}


	/**
	 * Action: add venue
	 */
	public function action_add() {
		if (!$this->request->param('id') && $this->ajax) {
			return $this->_edit_venue_dialog();
		} else {
			return $this->_edit_venue();
		}
	}


	/**
	 * Action: combine
	 */
	public function action_combine() {
		$this->history = false;

		// Load original venue
		$venue_id = (int)$this->request->param('id');
		$venue = Model_Venue::factory($venue_id);
		if (!$venue->loaded()) {
			throw new Model_Exception($venue, $venue_id);
		}

		// Load duplicate venue
		$duplicate_id = (int)$this->request->param('param');
		$duplicate = Model_Venue::factory($duplicate_id);
		if (!$duplicate->loaded()) {
			throw new Model_Exception($duplicate, $duplicate_id);
		}

		Permission::required($venue, Model_Venue::PERMISSION_COMBINE, self::$user);

		if (Security::csrf_valid()) {

			// Update events
			Model_Event::merge_venues($venue_id, $duplicate_id);

			// Remove duplicate
			$duplicate->delete();

		}

		$this->request->redirect(Route::model($venue));
	}


	/**
	 * Action: delete venue
	 */
	public function action_delete() {
		$this->history = false;

		// Load venue
		$venue_id = (int)$this->request->param('id');
		$venue = Model_Venue::factory($venue_id);
		if (!$venue->loaded()) {
			throw new Model_Exception($venue, $venue_id);
		}

		Permission::required($venue, Model_Venue::PERMISSION_DELETE, self::$user);

		if (!Security::csrf_valid()) {
			$this->request->redirect(Route::model($venue));
		}

		$venue->delete();

		$this->request->redirect(Route::get('venues')->uri());
	}


	/**
	 * Action: edit venue
	 */
	public function action_edit() {
		$this->_edit_venue((int)$this->request->param('id'));
	}


	/**
	 * Action: foursquare
	 */
	public function action_foursquare() {
		$this->history = false;

		// Load venue
		$venue_id = (int)$this->request->param('id');
		$venue = Model_Venue::factory($venue_id);
		if (!$venue->loaded()) {
			throw new Model_Exception($venue, $venue_id);
		}

		Permission::required($venue, Model_Venue::PERMISSION_UPDATE, self::$user);

		if (Security::csrf_valid() && isset($_POST['foursquare_id'])) {
			try {
				$venue->set_fields(Arr::intersect($_POST, array(
					'foursquare_id', 'foursquare_category_id', 'latitude', 'longitude', 'city_id', 'address'
				)));
				$venue->save();

				NewsfeedItem_Venues::venue_edit(self::$user, $venue);
			} catch (Validation_Exception $e) {

			}
		}

		$this->request->redirect(Route::model($venue));
	}


	/**
	 * Action: image
	 */
	public function action_image() {
		$this->history = false;

		// Load venue
		$venue_id = (int)$this->request->param('id');
		$venue = Model_Venue::factory($venue_id);
		if (!$venue->loaded()) {
			throw new Model_Exception($venue, $venue_id);
		}
		Permission::required($venue, Model_Venue::PERMISSION_UPDATE, self::$user);

		if (!$this->ajax) {
			$this->page_title    = HTML::chars($venue->name);
		}

		// Change existing
		if (isset($_REQUEST['default'])) {
			$image = Model_Image::factory((int)$_REQUEST['default']);
			if (Security::csrf_valid() && $image->loaded() && $venue->has('images', $image)) {
				$venue->default_image = $image;
				$venue->save();
			}
			$cancel = true;
		}

		// Delete existing
		if (isset($_REQUEST['delete'])) {
			$image = Model_Image::factory((int)$_REQUEST['delete']);
			if (Security::csrf_valid() && $image->loaded() && $image->id != $venue->default_image->id && $venue->has('images', $image)) {
				$venue->remove('images', $image);
				$venue->save();
				$image->delete();
			}
			$cancel = true;
		}

		// Cancel change
		if (isset($cancel) || isset($_REQUEST['cancel'])) {
			if ($this->ajax) {
				$this->response->body($this->_get_mod_image($venue));
				return;
			}

			$this->request->redirect(Route::model($venue));
		}

		$image = Model_Image::factory();
		$image->author_id = self::$user->id;

		// Handle post
		$errors = array();
		if ($_POST && $_FILES && Security::csrf_valid()) {
			$image->file = Arr::get($_FILES, 'file');
			try {
				$image->save();

				// Add exif, silently continue if failed - not critical
				try {
					$exif = Model_Image_Exif::factory();
					$exif->image_id = $image->id;
					$exif->save();
				} catch (Kohana_Exception $e) { }

				// Set the image as venue image
				$venue->relate('images', $image->id);
				$venue->default_image_id = $image->id;
				$venue->save();

				if ($this->ajax) {
					$this->response->body($this->_get_mod_image($venue));
					return;
				}

				$this->request->redirect(Route::model($venue));

			} catch (Validation_Exception $e) {
				$errors = $e->array->errors('validation');
			} catch (Kohana_Exception $e) {
				$errors = array('file' => __('Failed with image'));
			}
		}

		// Build form
		// @todo Fix to use custom view!
		$form = array(
			'ajaxify'    => $this->ajax,
			'values'     => $image,
			'errors'     => $errors,
			'attributes' => array('enctype' => 'multipart/form-data'),
			'cancel'     => $this->ajax ? Route::model($venue, 'image') . '?cancel' : Route::model($venue),
			'groups'     => array(
				array(
					'fields' => array(
						'file' => array(),
					),
				),
			)
		);

		$view = View_Module::factory('form/anqh', array(
			'mod_title' => __('Add image'),
			'form'      => $form
		));

		if ($this->ajax) {
			$this->response->body($view);
			return;
		}

		Widget::add('main', $view);
	}


	/**
	 * Controller default action
	 */
	public function action_index() {

		// Set actions
		if (Permission::has(new Model_Venue, Model_Venue::PERMISSION_CREATE, self::$user)) {
			$this->page_actions[] = array('link' => Route::get('venue_add')->uri(), 'text' => __('Add venue'), 'class' => 'venue-add');
		}

		Widget::add('main', View_Module::factory('venues/cities', array(
			'venues' => Model_Venue::factory()->find_all(),
		)));

		$this->_tabs();
	}


	/**
	 * Action: venue
	 */
	public function action_venue() {
		$venue_id =(int)$this->request->param('id');

		// Load venue
		/** @var  Model_Venue  $venue */
		$venue = Model_Venue::factory($venue_id);
		if (!$venue->loaded()) {
			throw new Model_Exception($venue, $venue_id);
		}

		$this->page_title = HTML::chars($venue->name);
		$this->page_subtitle = HTML::anchor(Route::get('venues')->uri(), __('Back to Venues'));

		// Set actions
		if (Permission::has($venue, Model_Venue::PERMISSION_UPDATE, self::$user)) {
			$this->page_actions[] = array('link' => Route::model($venue, 'edit'), 'text' => __('Edit venue'), 'class' => 'venue-edit');
		}

		// Events
		$events = $venue->find_events_upcoming(10);
		if (count($events)) {
			Widget::add('main', View_Module::factory('events/event_list', array(
				'mod_id'    => 'venue-upcoming-events',
				'mod_title' => __('Upcoming events'),
				'events'    => $events,
			)));
		}

		$events = $venue->find_events_past(10);
		if (count($events)) {
			Widget::add('main', View_Module::factory('events/event_list', array(
				'mod_id'    => 'venue-past-events',
				'mod_title' => __('Past events'),
				'events'    => $events,
			)));
		}

		// Similar venues
		$similar = Model_Venue::factory()->find_by_name($venue->name);
		if (count($similar) > 1) {
			Widget::add('main', View_Module::factory('venues/similar', array(
				'mod_title' => __('Similar venues'),
				'venue'     => $venue,
				'venues'    => $similar,
				'admin'     => Permission::has($venue, Model_Venue::PERMISSION_COMBINE, self::$user)
			)));
		}

		// Slideshow
		if (count($venue->images) > 1) {
			$images = array();
			foreach ($venue->images as $image) $images[] = $image;
			Widget::add('side', View_Module::factory('generic/image_slideshow', array(
				'images'     => array_reverse($images),
				'default_id' => $venue->default_image->id,
			)));
		}

		// Default image
		Widget::add('side', $this->_get_mod_image($venue));

		// Venue info
		Widget::add('side', View_Module::factory('venues/info', array(
			'admin' => Permission::has($venue, Model_Venue::PERMISSION_UPDATE, self::$user),
			'venue' => $venue,
			'foursquare' => $venue->foursquare(),
		)));
	}


	/**
	 * Edit venue
	 *
	 * @param  integer  $venue_id
	 */
	protected function _edit_venue($venue_id = null) {
		$this->history = false;
		$edit = true;

		if ($venue_id) {

			// Editing old
			$venue = Model_Venue::factory($venue_id);
			if (!$venue->loaded()) {
				throw new Model_Exception($venue, $venue_id);
			}
			Permission::required($venue, Model_Venue::PERMISSION_UPDATE, self::$user);
			$cancel = Route::model($venue);

			$this->page_title = HTML::chars($venue->name);

			// Set actions
			if (Permission::has($venue, Model_Venue::PERMISSION_DELETE, self::$user)) {
				$this->page_actions[] = array('link' => Route::model($venue, 'delete') . '?' . Security::csrf_query(), 'text' => __('Delete venue'), 'class' => 'venue-delete');
			}

		} else {

			// Creating new
			$edit = false;
			$venue = Model_Venue::factory();
			$venue->author_id = self::$user->id;
			$cancel = Route::get('venues')->uri();

		}

		// Handle post
		$errors = array();
		if ($_POST && Security::csrf_valid()) {
			$venue->set_fields(Arr::intersect($_POST, Model_Venue::$editable_fields));

			// GeoNames
			if ($_POST['city_id'] && $city = Geo::find_city((int)$_POST['city_id'])) {
				$venue->geo_city_id = $city->id;
			}

			try {
				$venue->save();

				$edit ? NewsfeedItem_Venues::venue_edit(self::$user, $venue) : NewsfeedItem_Venues::venue(self::$user, $venue);

				$this->request->redirect(Route::model($venue));
			} catch (Validation_Exception $e) {
				$errors = $e->array->errors('validation');
			}
		}

		Widget::add('wide', View_Module::factory('venues/edit', array('venue' => $venue, 'errors' => $errors, 'cancel' => $cancel)));
	}


	/**
	 * Edit venue data in dialog
	 */
	protected function _edit_venue_dialog() {
		echo View_Module::factory('venues/edit_dialog', array(

		));
	}


	/**
	 * Get image mod
	 *
	 * @param   Model_Venue  $venue
	 * @return  View_Module
	 */
	protected function _get_mod_image(Model_Venue $venue) {
		return View_Module::factory('generic/side_image', array(
			'mod_actions2' => Permission::has($venue, Model_Venue::PERMISSION_UPDATE, self::$user)
				? array(
						array('link' => Route::model($venue, 'image') . '?' . Security::csrf_query() . '&delete', 'text' => __('Delete'), 'class' => 'image-delete disabled'),
						array('link' => Route::model($venue, 'image') . '?' . Security::csrf_query() . '&default', 'text' => __('Set as default'), 'class' => 'image-default disabled'),
						array('link' => Route::model($venue, 'image'), 'text' => __('Add image'), 'class' => 'image-add ajaxify')
					)
				: null,
			'image' => $venue->default_image_id ? $venue->default_image_id : null,
		));
	}


	/**
	 * New and updated venues
	 */
	protected function _tabs() {
		$tabs = array(
			'new' => array('href' => '#venues-new', 'title' => __('New venues'), 'tab' => View_Module::factory('venues/list', array(
				'mod_id'    => 'venues-new',
				'mod_class' => 'cut tab venues',
				'title'     => __('New Venues'),
				'venues'    => Model_Venue::factory()->find_new(20),
			))),
			'updated' => array('href' => '#venues-updated', 'title' => __('Updated venues'), 'tab' => View_Module::factory('venues/list', array(
				'mod_id'    => 'venues-updated',
				'mod_class' => 'cut tab venues',
				'title'     => __('Updated Venues'),
				'venues'    => Model_Venue::factory()->find_updated(20),
			))),
		);

		Widget::add('side', View::factory('generic/tabs_side', array('id' => 'venues-tab', 'tabs' => $tabs)));
	}


}
