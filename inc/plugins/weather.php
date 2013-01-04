<?php
/**
 * MyBB 1.6
 * Copyright 2010 MyBB Group, All Rights Reserved
 *
 * Website: http://mybb.com
 * License: http://mybb.com/about/license
 *
 * $Id$
 */
 
// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

$plugins->add_hook("global_start", "weather_widget");

function weather_info()
{
	/**
	 * Array of information about the plugin.
	 * name: The name of the plugin
	 * description: Description of what the plugin does
	 * website: The website the plugin is maintained at (Optional)
	 * author: The name of the author of the plugin
	 * authorsite: The URL to the website of the author (Optional)
	 * version: The version number of the plugin
	 * guid: Unique ID issued by the MyBB Mods site for version checking
	 * compatibility: A CSV list of MyBB versions supported. Ex, "121,123", "12*". Wildcards supported.
	 */
	return array(
		"name"			=> "Weather",
		"description"	=> "Creates a weather widget that shows up in the board header (beneath div#panel).",
		"website"		=> "http://github.com/amwelles/mybb-weather",
		"author"		=> "Autumn Welles",
		"authorsite"	=> "http://novembird.com/mybb/",
		"version"		=> "0.1",
		"guid" 			=> "",
		"compatibility" => "*"
	);
}

/**
 * ADDITIONAL PLUGIN INSTALL/UNINSTALL ROUTINES
 *
 * _install():
 *   Called whenever a plugin is installed by clicking the "Install" button in the plugin manager.
 *   If no install routine exists, the install button is not shown and it assumed any work will be
 *   performed in the _activate() routine.
 *
 * function hello_install()
 * {
 * }
 *
 * _is_installed():
 *   Called on the plugin management page to establish if a plugin is already installed or not.
 *   This should return TRUE if the plugin is installed (by checking tables, fields etc) or FALSE
 *   if the plugin is not installed.
 *
 * function hello_is_installed()
 * {
 *		global $db;
 *		if($db->table_exists("hello_world"))
 *  	{
 *  		return true;
 *		}
 *		return false;
 * }
 *
 * _uninstall():
 *    Called whenever a plugin is to be uninstalled. This should remove ALL traces of the plugin
 *    from the installation (tables etc). If it does not exist, uninstall button is not shown.
 *
 * function hello_uninstall()
 * {
 * }
 *
 * _activate():
 *    Called whenever a plugin is activated via the Admin CP. This should essentially make a plugin
 *    "visible" by adding templates/template changes, language changes etc.
 *
 * function hello_activate()
 * {
 * }
 *
 * _deactivate():
 *    Called whenever a plugin is deactivated. This should essentially "hide" the plugin from view
 *    by removing templates/template changes etc. It should not, however, remove any information
 *    such as tables, fields etc - that should be handled by an _uninstall routine. When a plugin is
 *    uninstalled, this routine will also be called before _uninstall() if the plugin is active.
 *
 * function hello_deactivate()
 * {
 * }
 */

function weather_activate() {
	global $db, $lang;

	// create settings group
	$settingarray = array(
		'name' => 'weather',
		'title' => 'Weather',
		'description' => 'Settings for weather widget.',
		'disporder' => 100,
		'isdefault' => 0
	);

	$gid = $db->insert_query("settinggroups", $settingarray);

	// add settings
	$setting0 = array(
		"sid" => NULL,
		"name" => "weather_woeid",
		"title" => "Weather WOEID",
		"description" => "Enter a <a href=\"http://woeid.rosselliot.co.nz\">WOEID</a> from which to grab the weather.",
		"optionscode" => "text",
		"value" => NULL,
		"disporder" => 1,
		"gid" => $gid
	);

	$db->insert_query("settings", $setting0);

	$setting1 = array(
		"sid" => NULL,
		"name" => "weather_metric",
		"title" => "Celcius or Farenheit?",
		"description" => "Enter \'c\' or \'f\' (without quotes) to choose.",
		"optionscode" => "text",
		"value" => NULL,
		"disporder" => 2,
		"gid" => $gid
	);

	$db->insert_query("settings", $setting1);

	rebuild_settings();

	// set up templates
	$template0 = array(
		"tid" => NULL,
		"title" => "weather_widget",
		"template" => $db->escape_string('<strong>Currently:</strong> {$condition} &middot; <strong>Temperature:</strong> {$temp} &middot; <strong>Visibility:</strong> {$visibility} &middot; <strong>Humidity:</strong> {$humidity} &middot; <strong>Wind:</strong> {$wind}'),
		"sid" => "-1"
	);
	$db->insert_query("templates", $template0);

	// edit templates
	require_once MYBB_ROOT.'inc/adminfunctions_templates.php';

	// creates a link under online status
	find_replace_templatesets('header', '#'.preg_quote('{$welcomeblock}').'#', '{$welcomeblock}</div><div class="weather-widget">{$weather_widget}');
}

function weather_deactivate() {
	global $db, $mybb;

	// delete settings group
	$db->delete_query("settinggroups", "name = 'weather'");

	// remove settings
	$db->delete_query("settings", 'name IN ( \'weather_woeid\',\'weather_metric\' )');

	rebuild_settings();

	// delete templates
	$db->delete_query('templates', 'title IN ( \'weather_widget\' )');

	// edit templates
	require_once MYBB_ROOT.'inc/adminfunctions_templates.php';
	find_replace_templatesets('header', '#'.preg_quote('</div><div class="weather-widget">{$weather_widget}').'#', " ", 0);
}


function weather_widget() {
	global $mybb, $lang, $db, $weather_widget, $templates, $header, $footer, $headerinclude, $title, $theme, $current, $weather_woeid, $weather_metric;

	if(isset($mybb->settings['weather_woeid']) && $mybb->settings['weather_woeid'] != '') {
		$weather_woeid = $mybb->settings['weather_woeid'];
	} else {
		$weather_woeid = '2478307';
	}

	if(isset($mybb->settings['weather_metric']) && $mybb->settings['weather_metric']) {
		if($mybb->settings['weather_metric'] == 'c') {
			$weather_metric = 'c';
		}
		else {
			$weather_metric = 'f';
		}
	} else {
		$weather_metric = 'f';
	}

	// ---> 1) Get the data
 
	// Create a new cURL resource
	$ch = curl_init();
	 
	curl_setopt($ch, CURLOPT_URL, 'http://weather.yahooapis.com/forecastrss?w='. $weather_woeid .'&u='. $weather_metric .'');
	curl_setopt($ch, CURLOPT_HEADER, FALSE);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	 
	// Grab URL and pass it to the browser
	$data = curl_exec($ch);
	 
	// Close cURL resource, and free up system resources
	curl_close($ch);
	 
	// View response XML
	//echo "<pre>"; var_dump($data); echo "</pre>";
	 
	// Read in XML response to test for the last page
	$weather = simplexml_load_string($data);

	// ---> 2) Parse the data
	 
	/*
	  The following section of code was taken from these web sites on 9/18/2011. They
	  show how to capture custom namespace variables from XML.
	  http://pkarl.com/articles/parse-yahoo-weather-rss-using-php-and-simplexml-al/
	  http://snipt.net/pkarl/parse-yahoo-weather-feeds-with-simplexml-php
	*/
	 
	$channel_yweather = $weather->channel->children("http://xml.weather.yahoo.com/ns/rss/1.0");
	foreach($channel_yweather as $x => $channel_item){
	    foreach($channel_item->attributes() as $k => $attr)
	        $yw_channel[$x][$k] = $attr;
	}
	 
	 
	$item_yweather = $weather->channel->item->children("http://xml.weather.yahoo.com/ns/rss/1.0");
	foreach($item_yweather as $x => $yw_item) {
	    foreach($yw_item->attributes() as $k => $attr) {
	        if($k == 'day')
	          $day = $attr;
	        if($x == 'forecast'){
	          $yw_forecast[$x][$day . ''][$k] = $attr;
	        }else{
	          $yw_forecast[$x][$k] = $attr;
	        }
	    }
	}

	// change wind degrees to cardinal direction
	$bearing = $yw_channel['wind']['direction'][0];

	$cardinalDirections = array(
	  'N' => array(337.5, 22.5),
	  'NE' => array(22.5, 67.5),
	  'E' => array(67.5, 112.5),
	  'SE' => array(112.5, 157.5),
	  'S' => array(157.5, 202.5),
	  'SW' => array(202.5, 247.5),
	  'W' => array(247.5, 292.5),
	  'NW' => array(292.5, 337.5)
	);

	foreach ($cardinalDirections as $dir => $angles) {
	  if ($bearing >= $angles[0] && $bearing < $angles[1]) {
	    $direction = $dir;
	    break;
	  }
	}

	$condition = $yw_forecast['condition']['text'][0];
	$temp = $yw_forecast['condition']['temp'][0] ."&deg; ". $yw_channel['units']['temperature'][0];
	$visibility = $yw_channel['atmosphere']['visibility'][0] ." ". $yw_channel['units']['distance'][0];
	$humidity = $yw_channel['atmosphere']['humidity'][0];
	$wind = $yw_channel['wind']['speed'][0] ." ". $yw_channel['units']['speed'][0] ." ". $direction;

	eval("\$weather_widget = \"".$templates->get('weather_widget')."\";");

}
?>