<?php
/**
 * Test plugin for "HikAPI"
 *
 * Once installed, you can call the web-services using these URLs :
 *  http://localhost/index.php/hikapi/simple
 *  http://localhost/index.php/hikapi/param/2
 *  http://localhost/index.php/hikapi/param/99 (generate an error)
 *  http://localhost/index.php/hikapi/test-pagination
 *  http://localhost/index.php/hikapi/test-pagination/5-10
 *  http://localhost/index.php/hikapi/extclass
 *
 * You can also see the result in a specific format (JSON, XML, debug) by adding the parameter: ?format=xxx
 * By default the format is set to JSON but you can force the XML using:
 *   http://localhost/index.php/hikapi/myplugin/id:2?format=xml
 */
class plgHikapiTest extends HikApiPlugin {
	protected $urls = array(
		'/simple' => null, // Simple entry point
		'/param/:id' => null, // Entry point with a specified parameter
		'/test-pagination' => array( // Entry point with pagination
			'options' => array(
				'pagination' => true
			)
		),
		'/extclass' => array( // Entry point which specify an external class to call
			'ctrl' => 'plugin.test.extclass'
		)
	);

	/**
	 *
	 */
	public function onHikAPI(&$helper) {
		parent::onHikAPI($helper);

		// Add a code in the header, for every messages
		$helper->setHeader('code', 200);
	}

	/**
	 * One of the main function in the plugin, to "route" the call in your plugin
	 *
	 * @param  $helper  the helper object from the system plugin. (methods auth(), getSalt(), xmlExport() ...)
	 * @param  $url     the called url like specified by your plugin configuration.
	 * @param  $params  array of segment of the requested URL ; also contains the translated parameters.
	 * @param  $data    content of the data sent by the POST method.
	 */
	public function processRequest(&$helper, $url, $params, $data) {
		switch($url) {
			case '/simple':
				return $this->getSimpleValues($helper, $params, $data);
			case '/param':
				return $this->getParamsValues($helper, $params, $data);
			case '/test-pagination':
				return $this->getMyTestPagination($helper, $params, $data);
		}
		return false;
	}

	/**
	 * When using the "ctrl" parameter in your urls configuration, this function will be called to retrieved the associated class.
	 *
	 * @param  $ctrl   the text you specified in the urls configuration.
	 * @param  $ret    the reference for the return object. Is null by default.
	 */
	public function onHikAPIControllerGet($ctrl, &$ret) {
		parent::onHikAPIControllerGet($ctrl, $ret);

		if($ret === null && $ctrl == 'plugin.test.extclass')
			$ret = new hikapiTestExtClass();
	}
	
	/**
	 *
	 */
	public function getSimpleValues(&$helper, $params, $data) {

		// Objects will be serialized
		$oData = new JObject();
		$oData->set('name', 'Test user - object');
		$oData->set('id', 42);
		
		// Like arrays
		$aData = array(
			'name' => 'test user - array',
			'id' => 43
		);
		
		return array(
			'my content',
			$oData,
			$aData
		);
	}
	
	/**
	 *
	 */
	public function getParamsValues(&$helper, $params, $data) {
		
		// You can access to the $params['segments'] to see all segments of the url but you can also access directly to the name params.
		$id = (int)$params['params']['id'];

		if($id == 99) {
			$helper->setHeaders(array(
			  'code' => 500,
			  'message' => 'an error as occured'
			));
			return false;
		}

		return ('Content of the ID ' . $id);
	}

	/**
	 *
	 */
	public function getMyTestPagination(&$helper, $params, $data) {
		$start = 0;
		$limit = 10;
		if(isset($params['params']['pagination'])) {
			$start = ((int)$params['params']['pagination']['start']);
			$limit = ((int)$params['params']['pagination']['limit']);
			if($start <= 0)
				$start = 0;
			if($limit <= 0)
				$limit = 10;
		}

		$data = array();
		for($i = $start; $i <= $limit; $i++) {
			$data[$i] = md5($i);
		}
		return $data;
	}
}

/**
 *
 */
class hikapiTestExtClass {
	public function processRequest(&$helper, $url, $params, $data) {
		return 'From external class';
	}
}