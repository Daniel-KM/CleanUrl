<?php
/**
 * Clean Url
 *
 * Allows to have URL like http://example.com/my_collection/dc:identifier.
 *
 * @copyright Daniel Berthereau, 2012-2013
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */

/**
 * The Clean Url plugin.
 * @package Omeka\Plugins\CleanUrl
 */
class CleanUrlPlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_hooks = array(
        'install',
        'upgrade',
        'uninstall',
        'config_form',
        'config',
        'admin_items_browse_simple_each',
        'define_routes',
    );

    protected $_options = array(
        // 43 is the hard set id of "Dublin Core:Identifier" in default install.
        'clean_url_identifier_element' => 43,
        'clean_url_identifier_prefix' => 'document:',
        'clean_url_case_insensitive' => FALSE,
        'clean_url_main_path' => '',
        'clean_url_collection_generic' => '',
        'clean_url_item_default' => 'generic',
        'clean_url_item_alloweds' => 'a:1:{i:0;s:7:"generic";}',
        'clean_url_item_generic' => 'document/',
        'clean_url_file_default' => 'generic',
        'clean_url_file_alloweds' => 'a:1:{i:0;s:7:"generic";}',
        'clean_url_file_generic' => 'file/',
        'clean_url_display_admin_browse_identifier' => true,
    );

    /**
     * Installs the plugin.
     */
    public function hookInstall()
    {
        $this->_installOptions();
    }

    /**
     * Upgrades the plugin.
     */
    public function hookUpgrade($args)
    {
        $oldVersion = $args['old_version'];
        $newVersion = $args['new_version'];

        if (version_compare($oldVersion, '2.4', '<')) {
            set_option('clean_url_identifier_prefix', get_option('clean_url_item_identifier_prefix'));
            delete_option('clean_url_item_identifier_prefix');
            set_option('clean_url_case_insensitive', get_option('clean_url_case_insensitive'));
            set_option('clean_url_main_path', get_option('clean_url_generic_path'));
            delete_option('clean_url_generic_path');
            delete_option('clean_url_use_collection');
            delete_option('clean_url_collection_shortnames');
            set_option('clean_url_collection_generic', get_option('clean_url_collection_path'));
            delete_option('clean_url_collection_path');
            set_option('clean_url_item_url', get_option('clean_url_use_generic') ? 'generic' : 'collection');
            delete_option('clean_url_use_generic');
            set_option('clean_url_item_generic', get_option('clean_url_generic'));
            delete_option('clean_url_generic');
            set_option('clean_url_file_url', $this->_options['clean_url_file_url']);
            set_option('clean_url_file_generic', $this->_options['clean_url_file_generic']);
            set_option('clean_url_display_admin_browse_identifier', $this->_options['clean_url_display_admin_browse_identifier']);
        }

        if (version_compare($oldVersion, '2.8', '<')) {
            $itemUrl = get_option('clean_url_item_url');
            set_option('clean_url_item_default', $itemUrl);
            delete_option('clean_url_item_url');
            set_option('clean_url_item_alloweds', serialize(array($itemUrl)));

            $fileUrl = get_option('clean_url_file_url');
            set_option('clean_url_file_default', $fileUrl);
            delete_option('clean_url_file_url');
            set_option('clean_url_file_alloweds', serialize(array($fileUrl)));
        }

        if (version_compare($oldVersion, '2.9', '<')) {
            set_option('clean_url_identifier_element', $this->_options['clean_url_identifier_element']);
        }

        if (version_compare($oldVersion, '2.9.1', '<')) {
            foreach (array(
                    'clean_url_main_path',
                    'clean_url_collection_generic',
                    'clean_url_item_generic',
                    'clean_url_file_generic',
                ) as $option) {
                $path = get_option($option);
                if ($path) {
                    set_option($option, $path . '/');
                }
            }
        }
    }

    /**
     * Uninstalls the plugin.
     */
    public function hookUninstall()
    {
        $this->_uninstallOptions();
    }

    /**
     * Shows plugin configuration page.
     */
    public function hookConfigForm($args)
    {
        $view = $args['view'];
        echo $view->partial(
            'plugins/clean-url-config-form.php',
            array(
                'view' => $view,
            )
        );
    }

    /**
     * Saves plugin configuration page.
     *
     * @param array Options set in the config form.
     */
    public function hookConfig($args)
    {
        $post = $args['post'];

        // Sanitize first.
        $post['clean_url_identifier_prefix'] = $this->_sanitizePrefix($post['clean_url_identifier_prefix']);
        foreach (array(
                'clean_url_main_path',
                'clean_url_collection_generic',
                'clean_url_item_generic',
                'clean_url_file_generic',
            ) as $posted) {
            $value = trim($post[$posted], ' /');
            $post[$posted] = empty($value) ? '' : $this->_sanitizeString($value) . '/';
        }

        // The default url should be allowed for items and files.
        $post['clean_url_item_alloweds'][] = $post['clean_url_item_default'];
        $post['clean_url_item_alloweds'] = array_values(array_unique($post['clean_url_item_alloweds']));
        $post['clean_url_file_alloweds'][] = $post['clean_url_file_default'];
        $post['clean_url_file_alloweds'] = array_values(array_unique($post['clean_url_file_alloweds']));

        foreach (array(
                'clean_url_item_alloweds',
                'clean_url_file_alloweds',
            ) as $posted) {
            $post[$posted] = isset($post[$posted])
                ? serialize($post[$posted])
                : serialize(array());
        }
        foreach ($post as $key => $value) {
            set_option($key, $value);
        }
    }

    /**
     * Add the identifiant in the list.
     */
    public function hookAdminItemsBrowseSimpleEach($args)
    {
        if (get_option('clean_url_display_admin_browse_identifier')) {
            $view = $args['view'];
            $item = $args['item'];
            $identifier = $view->getRecordIdentifier($item);
            echo '<div><span>' . ($identifier ?: '<strong>' . __('No document identifier.') . '</strong>') . '</span></div>';
       }
    }

    /**
     * Defines public routes "main_path / my_collection | generic / dc:identifier".
     *
     * @todo Rechecks performance of routes definition.
     */
    public function hookDefineRoutes($args)
    {
        if (is_admin_theme()) {
            return;
        }

        $router = $args['router'];
        $view = get_view();

        $mainPath = get_option('clean_url_main_path');
        $collectionGeneric = get_option('clean_url_collection_generic');
        $itemGeneric = get_option('clean_url_item_generic');
        $fileGeneric = get_option('clean_url_file_generic');

        $allowedForItems = unserialize(get_option('clean_url_item_alloweds'));
        $allowedForFiles = unserialize(get_option('clean_url_file_alloweds'));

        // For performance and security reasons, one route is added for each
        // collection instead of one jokerised main route.
        // TODO Recheck in order to simplify or let.
        $collections = get_records('Collection', array(), 0);
        foreach ($collections as $collection) {
            $collectionIdentifier = $view->getRecordIdentifier($collection);
            if (empty($collectionIdentifier)) {
                continue;
            }

            // Add a route for the collection show view.
            $route = $mainPath . $collectionGeneric . $collectionIdentifier;
            $router->addRoute('cleanUrl_collection_' . $collection->id, new Zend_Controller_Router_Route(
                $route,
                array(
                    'module' => 'clean-url',
                    'controller' => 'index',
                    'action' => 'collection-show',
                    'collection_id' => $collection->id,
            )));

            // Add a lowercase route to prevent some practical issues.
            if ($route != strtolower($route)) {
                $router->addRoute('cleanUrl_collection_' . $collection->id . '_lower', new Zend_Controller_Router_Route(
                    strtolower($route),
                    array(
                        'module' => 'clean-url',
                        'controller' => 'index',
                        'action' => 'collection-show',
                        'collection_id' => $collection->id,
                )));
            }

            // Add a collection route for items.
            if (in_array('collection', $allowedForItems)) {
                $route = $mainPath . $collectionIdentifier;
                $router->addRoute('cleanUrl_collection_' . $collection->id . '_item', new Zend_Controller_Router_Route(
                    $route . '/:record-identifier',
                    array(
                        'module' => 'clean-url',
                        'controller' => 'index',
                        'action' => 'route-collection-item',
                        'collection_id' => $collection->id,
                )));

                // Add a lowercase route to prevent some practical issues.
                if ($route != strtolower($route)) {
                    $router->addRoute('cleanUrl_collection_' . $collection->id . '_item_lower', new Zend_Controller_Router_Route(
                        strtolower($route) . '/:record-identifier',
                        array(
                            'module' => 'clean-url',
                            'controller' => 'index',
                            'action' => 'route-collection-item',
                            'collection_id' => $collection->id,
                    )));
                }
            }

            // Add a collection route for files.
            if (in_array('collection', $allowedForFiles)) {
                $route = $mainPath . $collectionIdentifier;
                $router->addRoute('cleanUrl_collection_' . $collection->id . '_file', new Zend_Controller_Router_Route(
                    $route . '/:record-identifier',
                    array(
                        'module' => 'clean-url',
                        'controller' => 'index',
                        'action' => 'route-collection-file',
                        'collection_id' => $collection->id,
                )));

                // Add a lowercase route to prevent some practical issues.
                if ($route != strtolower($route)) {
                    $router->addRoute('cleanUrl_collection_' . $collection->id . '_file_lower', new Zend_Controller_Router_Route(
                        strtolower($route) . '/:record-identifier',
                        array(
                            'module' => 'clean-url',
                            'controller' => 'index',
                            'action' => 'route-collection-file',
                            'collection_id' => $collection->id,
                    )));
                }
            }

            // Add a collection / item route for files.
            if (in_array('collection_item', $allowedForFiles)) {
                $route = $mainPath . $collectionIdentifier;
                $router->addRoute('cleanUrl_collection_item_' . $collection->id . '_file', new Zend_Controller_Router_Route(
                    $route . '/:item-record-identifier/:record-identifier',
                    array(
                        'module' => 'clean-url',
                        'controller' => 'index',
                        'action' => 'route-collection-item-file',
                        'collection_id' => $collection->id,
                )));

                // Add a lowercase route to prevent some practical issues.
                if ($route != strtolower($route)) {
                    $router->addRoute('cleanUrl_collection_item_' . $collection->id . '_file_lower', new Zend_Controller_Router_Route(
                        strtolower($route) . '/:item-record-identifier/:record-identifier',
                        array(
                            'module' => 'clean-url',
                            'controller' => 'index',
                            'action' => 'route-collection-item-file',
                            'collection_id' => $collection->id,
                    )));
                }
            }
        }

        // Add a generic route for items.
        if (in_array('generic', $allowedForItems)) {
            $route = $mainPath . trim($itemGeneric, '/');
            $router->addRoute('cleanUrl_generic_items_browse', new Zend_Controller_Router_Route(
                $route,
                array(
                    'module' => 'clean-url',
                    'controller' => 'index',
                    'action' => 'items-browse',
            )));
            $router->addRoute('cleanUrl_generic_item', new Zend_Controller_Router_Route(
                $route . '/:record-identifier',
                array(
                    'module' => 'clean-url',
                    'controller' => 'index',
                    'action' => 'route-item',
                    'collection_id' => NULL,
            )));

            // Add a lowercase route to prevent some practical issues.
            if ($route != strtolower($route)) {
                $router->addRoute('cleanUrl_generic_items_browse_lower', new Zend_Controller_Router_Route(
                    strtolower($route),
                    array(
                        'module' => 'clean-url',
                        'controller' => 'index',
                        'action' => 'items-browse',
                )));
                $router->addRoute('cleanUrl_generic_item_lower', new Zend_Controller_Router_Route(
                    strtolower($route) . '/:record-identifier',
                    array(
                        'module' => 'clean-url',
                        'controller' => 'index',
                        'action' => 'route-item',
                        'collection_id' => NULL,
                )));
            }
        }

        // Add a generic route for files.
        if (in_array('generic', $allowedForFiles)) {
            $route = $mainPath . $fileGeneric;
            $router->addRoute('cleanUrl_generic_file', new Zend_Controller_Router_Route(
                $route . ':record-identifier',
                array(
                    'module' => 'clean-url',
                    'controller' => 'index',
                    'action' => 'route-file',
                    'collection_id' => NULL,
            )));

            // Add a lowercase route to prevent some practical issues.
            if ($route != strtolower($route)) {
                $router->addRoute('cleanUrl_generic_file_lower', new Zend_Controller_Router_Route(
                    strtolower($route) . ':record-identifier',
                    array(
                        'module' => 'clean-url',
                        'controller' => 'index',
                        'action' => 'route-file',
                        'collection_id' => NULL,
                )));
            }
        }

        // Add a generic / item route for files.
        if (in_array('generic_item', $allowedForFiles)) {
            $route = $mainPath . $fileGeneric;
            $router->addRoute('cleanUrl_generic_item_file', new Zend_Controller_Router_Route(
                $route . ':item-record-identifier/:record-identifier',
                array(
                    'module' => 'clean-url',
                    'controller' => 'index',
                    'action' => 'route-item-file',
                    'collection_id' => NULL,
            )));

            // Add a lowercase route to prevent some practical issues.
            if ($route != strtolower($route)) {
                $router->addRoute('cleanUrl_generic_item_file_lower', new Zend_Controller_Router_Route(
                    strtolower($route) . ':item-record-identifier/:record-identifier',
                    array(
                        'module' => 'clean-url',
                        'controller' => 'index',
                        'action' => 'route-item-file',
                        'collection_id' => NULL,
                )));
            }
        }
    }

    /**
     * Returns a sanitized and unaccentued string for folder or file path.
     *
     * @param string $string The string to sanitize.
     *
     * @return string The sanitized string to use as a folder or a file name.
     */
    private function _sanitizeString($string)
    {
        return $this->_sanitizeAnyString($string, '');
    }

    /**
      * Returns a sanitized and unaccentued string for prefix.
     *
     * Difference with default sanitization is that space is allowed.
     *
     * @param string $string The string to sanitize.
     *
     * @return string The sanitized string to use as a prefix.
     */
    private function _sanitizePrefix($string)
    {
        return $this->_sanitizeAnyString($string, ' ');
    }

    /**
     * Returns a sanitized and unaccentued string for folder or file path.
     *
     * @param string $string The string to sanitize.
     * @param string $space Add space as an allowed characters.
     *
     * @return string The sanitized string to use as a folder or a file name.
     */
    private function _sanitizeAnyString($string, $space = '')
    {
        $string = trim(strip_tags($string));
        $string = htmlentities($string, ENT_NOQUOTES, 'utf-8');
        $string = preg_replace('#\&([A-Za-z])(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml)\;#', '\1', $string);
        $string = preg_replace('#\&([A-Za-z]{2})(?:lig)\;#', '\1', $string);
        $string = preg_replace('#\&[^;]+\;#', '_', $string);
        $string = preg_replace('/[^[:alnum:]\(\)\[\]_\-\.#~@+:' . $space . ']/', '_', $string);
        return preg_replace('/_+/', '_', $string);
    }
}
