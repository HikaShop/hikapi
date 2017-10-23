<?php
/**
 * HikAPI Helper class
 */
class HikApiHelper {
	protected $routes = array();
	protected $render_format = 'json';

	// Local temp variable used for route filters
	protected $tmpRouteFilters = null;

	// Part of the answer message, for the "header" content
	protected $answer = array();

	protected $plugin_params = null;

	/**
	 *
	 */
	public function __construct($plugin_params) {
		if(version_compare(JVERSION, '2.5', '<')) {
			$format = JRequest::getCmd('format', 'json');
		} else {
			$app = JFactory::getApplication();
			$format = $app->input->getCmd('format', 'json');
		}
		$this->setOutputFormat($format);
		$this->plugin_params = $plugin_params;
	}

	/**
	 *
	 */
	public function processQuery($query = null) {
		// If no query, retrive it
		if(empty($query))
			$query = $this->getQuery();
		if(empty($query))
			return false;

		// Register the plugins
		//
		JPluginHelper::importPlugin('hikapi');
		$dispatcher = JDispatcher::getInstance();
		$dispatcher->trigger('onHikAPI', array(&$this));

		$route = $this->getRoute($query);
		if(empty($route))
			return false;

		// The route is an alias
		//
		if(isset($this->routes[$route]) && is_string($this->routes[$route]) && substr($this->routes[$route], 0, 1) == '/') {
			//
			// TODO : improve alias management, with parameters for example
			//
			$alias_query = $this->routes[$route];
			$alias = $this->getRoute($alias_query);

			if(!empty($alias) && isset($this->routes[$alias]) && is_array($this->routes[$alias])) {
				$query = $alias_query;
				$route = $alias;
			}
		}

		$params = $this->getRouteParams($query, $route);
		$data = $this->getData();

		$ctrl = $this->getController(@$this->routes[$route]['ctrl']);
		if(empty($ctrl) || !method_exists($ctrl, 'processRequest'))
			return false;

		if(!empty($data['user']) && !empty($data['token']))
			$this->checkToken($data);

		$ret = $ctrl->processRequest($this, $route, $params, $data);

		$answer = $this->answer;
		$answer['content'] = $ret;
		$this->render($answer);
	}

	/**
	 *
	 */
	protected function getRoute($query) {
		// Get the valid routes
		//
		$validRoutes = array();
		foreach($this->routes as $route => $data) {
			if(strpos($route, ':') === false) {
				if(strpos($query, $route) !== 0)
					continue;

				$validRoutes[$route] = $route;
			} else {
				if(empty($data['options']['filters']))
					$regex_route = $this->getRegex($route);
				else
					$regex_route = $this->getRegex($route, $data['options']['filters']);

				if( !preg_match('#^'.$regex_route.'(/.*)?$#i', $query, $matches))
					continue;

				$validRoutes[$route] = $route;
			}
		}

		// No route found
		//
		if(empty($validRoutes))
			return false;

		// Get the route with the most parts
		//
		$sort = array();
		foreach($validRoutes as $k => $v) {
			$sort[$k] = count(explode('/', $k));
		}
		// Sort the routes depending the number of parts
		arsort($sort);
		// Get best (first) route
		$f = key($sort);

		return $f;
	}

	/**
	 *
	 */
	protected function getRouteParams($query, $route) {
		if(!isset($this->routes[$route]))
			return false;

		$data = $this->routes[$route];
		$params = array();
		if(strpos($route, ':') !== false) {
			if(empty($data['options']['filters']))
				$regex_route = $this->getRegex($route);
			else
				$regex_route = $this->getRegex($route, $data['options']['filters']);

			if(preg_match('#^'.$regex_route.'(/.*)?$#i', $query, $matches) && preg_match_all('#:(\w+)#', $route, $arguments)) {
				// loop trough parameter names, store matching value in $params array
				foreach($arguments[1] as $key => $name) {
					if(isset($matches[$key + 1]))
						$params[$name] = $matches[$key + 1];
				}
			}
		}

		$segments = array();
		$query_segments = explode('/', ltrim($query, '/'));
		$route_segments = explode('/', ltrim($route, '/'));
		foreach($query_segments as $k => $v) {
			if(isset($route_segments[$k]) && substr($route_segments[$k], 0, 1) == ':' && isset($params[substr($route_segments[$k], 1)])) {
				$key = substr($route_segments[$k], 1);
				$segments[$key] = $v;
				continue;
			}
			if(!empty($data['options']['pagination']) && empty($segments['pagination']) && preg_match('#^([\d]+)-([\d]+)$#i', $v, $matches)) {
				$pagination = array(
					'start' => $matches[1],
					'limit' => $matches[2],
				);
				$segments['pagination'] = $pagination;
				$params['pagination'] = $pagination;
				continue;
			}
			$segments[] = $v;
		}

		return array(
			'params' => $params,
			'segments' => $segments
		);;
	}

	/**
	 *
	 */
	public function getRegex($route, $filters = null) {
		if(empty($filters))
			return preg_replace(array('#:id#','#:(\w+)#'), array('([\d]+)','([\w]+)'), $route);

		$this->tmpRouteFilters = $filters;
		$ret = preg_replace_callback('#:(\w+)#', array(&$this, 'substituteRouteFilter'), $route);
		unset($this->tmpRouteFilters);
		return $ret;
	}

	/**
	 *
	 */
	private function substituteRouteFilter($matches) {
		if(isset($matches[1]) && isset($this->tmpRouteFilters[$matches[1]]))
			return $this->tmpRouteFilters[$matches[1]];

		if(isset($matches[1]) && $matches[1] == 'id')
			return '([\d]+)';

		return '([\w]+)';
	}

	/**
	 *
	 */
	public function getQuery() {
		return null;
	}

	/**
	 *
	 */
	public function getData() {
		if(isset($_POST['data'])) {
			$data = trim($_POST['data']);
			if(!empty($data))
				return json_decode($data, true);
			return null;
		}

		$raw_data = isset($HTTP_RAW_POST_DATA) ? trim($HTTP_RAW_POST_DATA) : trim(file_get_contents('php://input'));
		if(!empty($raw_data))
			return json_decode($data, true);
		return null;
	}

	/**
	 *
	 */
	public function getController($ctrl) {
		if(empty($ctrl))
			return false;
		if(is_object($ctrl))
			return $ctrl;

		$ret = null;
		JPluginHelper::importPlugin('hikapi');
		$dispatcher = JDispatcher::getInstance();
		$dispatcher->trigger('onHikAPIControllerGet', array($ctrl, &$ret));
		return $ret;
	}

	/**
	 *
	 */
	public function register($url, &$ctrl, $params = null, $options = null) {
		if(isset($this->routes[$url]))
			return false;

		$this->routes[$url] = array(
			'ctrl' => &$ctrl,
			'params' => $params,
			'options' => $options
		);
		return true;
	}

	/**
	 *
	 */
	public function registerAlias($url, $redirect) {
		if(isset($this->routes[$url]))
			return false;
		$this->routes[$url] = $redirect;
		return true;
	}

	/**
	 *
	 */
	public function render($content, $format = '') {
		while(ob_get_level())
			@ob_end_clean();

		if(empty($format) || !in_array($format, array('json','xml','debug')))
			$format = $this->render_format;

		if($format == 'debug') {
			header('Content-Type: text/html');

			$data = '';
			if(isset($_POST['data']))
				$data = trim($_POST['data']);
			else
				$data = isset($HTTP_RAW_POST_DATA) ? trim($HTTP_RAW_POST_DATA) : trim(file_get_contents('php://input'));

			echo '<form method="POST" action="">'.
				'<textarea autocomplete="off" name="data" style="width:100%">'.htmlentities($data).'</textarea><br/>'.
				'<input type="submit">'.
				'</form>';

			echo '<pre>'.print_r($content, true).'</pre>';
			exit;
		}

		if($format == 'xml') {
			if(!headers_sent())
				header('Content-Type: application/xml');
			$xml = new SimpleXMLElement('<hikapi/>');
			$this->xmlExport($xml, $content);
			echo $xml->asXML();
		} else {
			if(!headers_sent())
				header('Content-Type: application/json');
			echo json_encode($content);
		}
		exit;
	}

	/**
	 *
	 */
	public function setOutputFormat($format) {
		if(!empty($format) || in_array($format, array('json','xml','debug')))
			$this->render_format = $format;
	}

	/**
	 *
	 */
	public function xmlExport(&$output, $data, $d = 0) {
		if($d >= 50)
			return;
	    foreach($data as $key => $value) {
			if(is_array($value) || is_object($value)) {
				if(!is_numeric($key)) {
					$subnode = $output->addChild($key);
					$this->xmlExport($subnode, $value, $d+1);
				} else {
					$subnode = $output->addChild('item');
					$subnode->addAttribute('id', $key);
					$this->xmlExport($subnode, $value, $d+1);
				}
			} else if(is_numeric($key)) {
				$subnode = $output->addChild('item', $value);
				$subnode->addAttribute('id', $key);
			} else {
				$output->addChild($key, $value);
			}
		}
	}

	/**
	 *
	 */
	public function setHeader($name, $key) {
		if(!in_array($name, array('error', 'code', 'msg', 'message', 'token')))
			return;
		$this->answer[$name] = $key;
	}

	/**
	 *
	 */
	public function setHeaders($data) {
		if(!is_array($data) || empty($data))
			return;
		foreach($data as $k => $v) {
			$this->setHeader($k, $v);
		}
	}

	/**
	 *
	 */
	public function auth($data) {
		if(empty($data['username']) || empty($data['password'])) {
			$this->setHeaders(array(
				'error' => 500,
			));
			return false;
		}

		$options = array(
			'remember' => false,
			'return' => false,
		);
		$credentials = array(
			'username' => $data['username'],
			'password' => $data['password'],
		);

		$app = JFactory::getApplication();
		$error = $app->login($credentials, $options);
		$user = JFactory::getUser();
		if(JError::isError($error)) {
			$this->setHeaders(array(
				'error' => $error,
			));
			return false;
		}
		if($user->guest) {
			$this->setHeaders(array(
				'error' => 401,
			));
			return false;
		}

		$api_salt = $this->getSalt();
		$timestamp = time();
		$timestamp -= ($timestamp % 60);

		$token_frame = (int)$this->plugin_params->get('token_frame', 15);
		if($token_frame < 2)
			$token_frame = 2;
		$timestamp -= ($timestamp % ($token_frame * 60));

		return array(
			'user' => $data['username'],
			'token' => sha1((int)$user->id . '#' . $user->registerDate .  '#' . $user->email . '#' . date('dmY:Hi', $timestamp) . '#' . $api_salt),
		);
	}

	/**
	 *
	 */
	public function checkToken($data) {
		if(empty($data['user']) || empty($data['token']))
			return false;

		$user_id = JUserHelper::getUserId($data['user']);
		if(empty($user_id))
			return false;

		$user = JFactory::getUser($user_id);

		$api_salt = $this->getSalt();
		$timestamp = time();
		$timestamp -= ($timestamp % 60);

		$token_frame = (int)$this->plugin_params->get('token_frame', 15);
		if($token_frame < 2)
			$token_frame = 2;
		$timestamp -= ($timestamp % ($token_frame * 60));

		// Generate current and previous tokens
		$token = sha1((int)$user->id . '#' . $user->registerDate .  '#' . $user->email . '#' . date('dmY:Hi', $timestamp) . '#' . $api_salt);
		$previous_token = sha1((int)$user->id . '#' . $user->registerDate .  '#' . $user->email . '#' . date('dmY:Hi', $timestamp - ($token_frame * 60)) . '#' . $api_salt);

		// Check current and previous token
		//
		if(($data['token'] == $token) || ($data['token'] == $previous_token)) {
			// Give the current token in the answer
			$this->setHeader('token', $token);
			// Set the user in the current session, for the rest of the API processing
			JFactory::getSession()->set('user', $user);

			return true;
		}
		return false;
	}

	/**
	 *
	 */
	public function getSalt() {
		$ret = $this->plugin_params->get('salt', null);
		if(!empty($ret))
			return $ret;

		// Generate Salt
		$ret = $this->generateSalt();
		$this->plugin_params->set('salt', $ret);

		// Save the salt in the plugin parameters
		//
		$db = JFactory::getDBO();

		if(version_compare(JVERSION, '2.5', '<')) {
			$table = '#__plugins';
			$filters = array(
				'element = ' . $db->Quote('hikapi'),
				'folder = ' . $db->Quote('system'),
				'published = 1',
			);

			$params = '';
			foreach($this->plugin_params as $key => $val) {
				$params .= $key . '=' . $val . "\n";
			}
			$params = rtrim($params);

			$query = 'UPDATE ' . $table . ' SET params = ' . $db->Quote($params) . ' WHERE (' . implode(') AND (', $filters) . ')';
			$db->setQuery($query);
			$db->query();
		} else {
			$handler = JRegistryFormat::getInstance('JSON');
			$params = $handler->objectToString($this->plugin_params);

			$query = $db->getQuery(true);
			$query->update($db->quoteName('#__extensions'))
				->set( $db->quoteName('params') . ' = ' . $db->quote($params) )
				->where(array(
					$db->quoteName('type') . ' = ' . $db->quote('plugin'),
					$db->quoteName('element') . '= ' . $db->quote('hikapi'),
					$db->quoteName('folder') . ' = ' . $db->quote('system'),
					$db->quoteName('enabled') . ' = 1',
				));

			$db->setQuery($query);
			$db->execute();
		}

		return $ret;
	}

	/**
	 *
	 */
	protected function generateSalt($salt_size = 30, $fastRandom = false) {
		// Content data for randomize
		$charList = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789+-*/&_()!:;,.$=[]@|~?%{}#~';
		$base = strlen($charList);
		$ret = '';

		if(version_compare(JVERSION, '2.5', '<') || $fastRandom) {
			// Init Randomize
			//
			$stat = @stat(__FILE__);
			if(empty($stat) || !is_array($stat)) $stat = array(php_uname());
			mt_srand(crc32(microtime() . implode('|', $stat)));

			// Generate salt
			//
			for($i = 1; $i <= $salt_size; $i++) {
				$ret .= $charList[mt_rand(0, $base - 1)];
			}
		} else {
			// Init Randomize
			//
			$rndCpt = 1;
			$random = JCrypt::genRandomBytes($salt_size + 1);
			$shift = ord($random[0]);

			// Generate salt
			//
			for($i = 1; $i <= $salt_size; $i++) {
				$ret .= $charList[($shift + ord($random[$rndCpt])) % $base];
				$shift += ord($random[$rndCpt++]);
				if($rndCpt == $salt_size) {
					$rndCpt = 1;
					$random = JCrypt::genRandomBytes($salt_size + 1);
					$shift = ord($random[0]);
				}
			}
		}

		return $ret;
	}
}

/**
 * HikAPI Plugin structure
 */
class HikApiPlugin extends JPlugin {
	protected $name = null;
	protected $urls = array();

	/**
	 *
	 */
	public function __construct(&$subject, $config) {
		parent::__construct($subject, $config);
	}

	/**
	 *
	 */
	public function onHikAPI(&$helper) {
		$this->registerAPI($helper);
	}

	/**
	 *
	 */
	public function onHikAPIControllerGet($ctrl, &$ret) {
		if($ret === null && !empty($this->name) && $ctrl == ('plugin.'.$this->name))
			$ret =& $this;
	}

	/**
	 *
	 */
	public function registerAPI(&$helper) {
		if(empty($this->urls))
			return;

		if(empty($this->name))
			$ctrl =& $this;
		else
			$ctrl = 'plugin.'.$this->name;

		foreach($this->urls as $key => $value) {
			if(is_int($key)) {
				$helper->register($value, $ctrl);
			} else {
				$params = null; $options = null; $l_ctrl = $ctrl;
				if(isset($value['params']))
					$params = $value['params'];
				if(isset($value['options']))
					$options = $value['options'];
				if(isset($value['ctrl']))
					$l_ctrl = $value['ctrl'];
				$helper->register($key, $l_ctrl, $params, $options);
				unset($l_ctrl);
			}
		}
	}

	/**
	 *
	 */
	public function processRequest(&$helper, $url, $params, $data) {
		return false;
	}
}