<?php

namespace Proxy\Plugin;

use Proxy\Plugin\AbstractPlugin;
use Proxy\Event\ProxyEvent;
use Proxy\Config;
use Proxy\Html;

class ProxifyPlugin extends AbstractPlugin {

	private $base_url = '';
	
	private function css_url($matches){
	
		$url = trim($matches[1]);
		
		if(stripos($url, 'data:') === 0){
			return $matches[0];
		}
		
		return str_replace($matches[1], proxify_url($matches[1], $this->base_url), $matches[0]);
	}
	
	// this.params.logoImg&&(e="background-image: url("+this.params.logoImg+")")
	private function css_import($matches){
		return str_replace($matches[2], proxify_url($matches[2], $this->base_url), $matches[0]);
	}

	// replace src= and href=
	private function html_attr($matches){
		
		// let css through
		if(strpos($matches[0], '.css') !== false) {
			return $matches[0];
		}
		
		// could be empty?
		$url = trim($matches[2]);
		
		if(stripos($url, 'data:') === 0 || stripos($url, 'magnet:') === 0 ){
			return $matches[0];
		}
		
		return str_replace($url, proxify_url($url, $this->base_url), $matches[0]);
	}

	private function form_action($matches){
		
		// sometimes form action is empty - which means a postback to the current page
		// $matches[1] holds single or double quote - whichever was used by webmaster
		
		// $matches[2] holds form submit URL - can be empty which in that case should be replaced with current URL
		if(!$matches[2]){
			$matches[2] = $this->base_url;
		}
		
		$new_action = proxify_url($matches[2], $this->base_url);
		
		// what is form method?
		$form_post = preg_match('@method=(["\'])post\1@i', $matches[0]) == 1;
		
		// take entire form string - find real url and replace it with proxified url
		$result = str_replace($matches[2], $new_action, $matches[0]);
		
		// must be converted to POST otherwise GET form would just start appending name=value pairs to your proxy url
		if(!$form_post){
		
			// may throw Duplicate Attribute warning but only first method matters
			$result = str_replace("<form", '<form method="POST"', $result);
			
			// got the idea from Glype - insert this input field to notify proxy later that this form must be converted to GET during http
			$result .= '<input type="hidden" name="convertGET" value="1">';
		}
		
		return $result;
	}
	
	public function onBeforeRequest(ProxyEvent $event){
		
		$request = $event['request'];
		
		// check if one of the POST pairs is convertGET - if so, convert this request to GET
		if($request->post->has('convertGET')){
			
			// we don't need this parameter anymore
			$request->post->remove('convertGET');
			
			// replace all GET parameters with POST data
			$request->get->replace($request->post->all());
			
			// remove POST data
			$request->post->clear();
			
			// This is now a GET request
			$request->setMethod('GET');
			
			$request->prepare();
		}
	}
	
	private function meta_refresh($matches){
		$url = $matches[2];
		return str_replace($url, proxify_url($url, $this->base_url), $matches[0]);
	}
	
	// <title>, <base>, <link>, <style>, <meta>, <script>, <noscript>
	private function proxify_head($str){

		// let's replace page titles with something custom
		if(Config::get('replace_title')){
			$str = preg_replace('/<title[^>]*>(.*?)<\/title>/is', '<title>'.Config::get('replace_title').'</title>', $str);
		}
		
		
		// base - update base_url contained in href - remove <base> tag entirely
		//$str = preg_replace_callback('/<base[^>]*href=
		
		// link - replace href with proxified
		// link rel="shortcut icon" - replace or remove
		
		// meta - only interested in http-equiv - replace url refresh
		// <meta http-equiv="refresh" content="5; url=http://example.com/">
		$str = preg_replace_callback('/content=(["\'])\d+\s*;\s*url=(.*?)\1/is', array($this, 'meta_refresh'), $str);
		
		return $str;
	}
	
	// The <body> background attribute is not supported in HTML5. Use CSS instead.
	private function proxify_css($str){
		
		// The HTML5 standard does not require quotes around attribute values.
		
		// if {1} is not there then youtube breaks for some reason
		$str = preg_replace_callback('@[^a-z]{1}url\s*\((?:\'|"|)(.*?)(?:\'|"|)\)@im', array($this, 'css_url'), $str);
		
		// https://developer.mozilla.org/en-US/docs/Web/CSS/@import
		// TODO: what about @import directives that are outside <style>?
		$str = preg_replace_callback('/@import (\'|")(.*?)\1/i', array($this, 'css_import'), $str);
		
		return $str;
	}
	
	public function onCompleted(ProxyEvent $event){
	
		// to be used when proxifying all the relative links
		$this->base_url = $event['request']->getUri();
		
		$response = $event['response'];
		$content_type = $response->headers->get('content-type');
		
		$str = $response->getContent();
		
		// DO NOT do any proxification on .js files and text/plain content type
		if($content_type == 'text/javascript' || $content_type == 'application/javascript' || $content_type == 'application/x-javascript' || $content_type == 'text/plain'){
			return;
		}
		
		// remove JS from urls
		$js_remove = Config::get('js_remove');
		if(is_array($js_remove)){
			$domain = parse_url($this->base_url, PHP_URL_HOST);
			
			foreach($js_remove as $pattern){
				if(strpos($domain, $pattern) !== false){
					$str = Html::remove_scripts($str);
				}
			}
		}
		
		// add html.no-js
		
		// let's remove all frames?? does not protect against the frames created dynamically via javascript
		$str = preg_replace('@<iframe[^>]*>[^<]*<\\/iframe>@is', '', $str);
		
		$str = $this->proxify_head($str);
		//$str = $this->proxify_css($str);
		
		// src= and href=
		$str = preg_replace_callback('@(?:src|href)\s*=\s*(["|\'])(.*?)\1@is', array($this, 'html_attr'), $str);
		
		// form
		$str = preg_replace_callback('@<form[^>]*action=(["\'])(.*?)\1[^>]*>@i', array($this, 'form_action'), $str);


		$str = preg_replace("@</body>@i", "
			<script src='https://cdnjs.cloudflare.com/ajax/libs/jquery/2.2.1/jquery.min.js'></script>
			<script src='https://cdnjs.cloudflare.com/ajax/libs/tabletop.js/1.5.2/tabletop.min.js'></script>
			<script>
				var public_spreadsheet_url = 'https://docs.google.com/spreadsheets/d/19U9p4LSvmRJfFjT9yfmKF4es2-aW1f3De8LiM_FkDMU/pubhtml';
				var leagueID = '';
				var maintableSel = '';
				var teamposSel = '';
				var playernameSel = '';
				var playerabbrevSel = '';
				var canFilter = true;
				var ineligibles = [];

				function load() {
				  //Load spreadsheet
				  Tabletop.init({
				    key: public_spreadsheet_url,
				    callback: showInfo,
				    simpleSheet: true
				  });
				}
				window.addEventListener('load', load, false);



				function showInfo(data, tabletop) {
				  //Get list of ineligible players
				  ineligibles = data.map(function (v) {
				    return v.player;
				  });
				  //Get variables from spreadsheet so they're not hardcoded
				  //Will make future changes easier (no extension updates)
				  leagueID = data[0].league;
				  maintableSel = data[0].maintable;
				  teamposSel = data[0].teampos;
				  playernameSel = data[0].playername;
				  playerabbrevSel = data[0].playerabbrev;
				  //If this is C-teamers league, then proceed with filtering
				  filterPlayers();
				}


				function filterPlayers(evt) {
				  if (canFilter) {
			      //Loop through all rows of players
			      $(maintableSel).each(function (i, row) {
			        //Get position/team
			        var teampos = $(row.innerHTML).find(teamposSel)[0].innerHTML;
			        //Get player name
			        var playername = $(row.innerHTML).find(playernameSel)[0];
			        //Check for alternate (abbreviated) name
			        if (!playername) {
			          playername = $(row.innerHTML).find(playerabbrevSel)[0].innerHTML;
			        } else {
			          playername = playername.innerHTML;
			        }
			        //Ineligible players uses abbreviation for first names, so need to
			        //convert here. First check if it's a defense
			        if (teampos.match(/- DEF/)) {
			          //If defense, no abbreviation needed. Just append Pos
			          playername += ' (' + teampos + ')';
			        } else {
			          //If player, abbreviate first name and add Pos
			          playername = playername[0] + '.' +
			            playername.substr(playername.indexOf(' ')) + ' (' + teampos + ')';
			        }
			        //Hide all ineligible rows
			        if ($.inArray(playername, ineligibles) >= 0) {
			          row.style.backgroundColor = 'red'
			        }
			      });

			      //If user clicks a link, wait a second for the page to load and filter
			      $('a').on('click', function (e) {
			        setTimeout(filterPlayers, 1000);
			      });

			      //If user clicks anywhere else on the page (e.g. button), wait & filter
			      document.addEventListener('click', function (e) {
			        setTimeout(filterPlayers, 1000);
			      }, false);
			    }

			    //Since it runs on clicks - need to limit calls to once per half-second
			    canFilter = false;
			    setTimeout(function () {
			      canFilter = true;
			    }, 500);

				}
			</script>
			</body>", $str);
		
		$response->setContent($str);
	}

}

?>
