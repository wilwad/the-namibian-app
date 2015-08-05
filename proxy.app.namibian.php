<?php
 /*
  * Proxy for The Namibian app
  *
  * this file does 2 things:
  *
  * 1. provides access-control origin to silence JQuery and AngularJS's $http() call
  * 2. parses The Namibian newspaper
  *
  * To reduce bandwidth consumption on the newspaper, we serve a cached copy & 
  * only reparse the website after a few hours
  *
  * idx -- index of current story. I was using $index of AngularJS but it does not 
  *        point to a valid story index when you run a filter on a list
  *
  * Written by William Sengdara
  * July 26, 2015
  */
  header("Access-Control-Allow-Origin: *");
  header("Content-Type: application/json");
  
  date_default_timezone_set('Africa/Windhoek');
  
  // PHP parser from github
  require_once("simple_html_dom.php");
  
  // parameters
  $view    = @ $_GET['view'];
  $url_    = @ $_GET['url'];
  $url     = str_replace("___","&",$url_);
  $url     = "http://www.namibian.com.na/" . $url;
  $siteurl = "http://www.namibian.com.na/";

  switch ($view){
	  case 'articles-list':	
	        $arr_search = array('.','?','&','=');
            $url_file = str_replace($arr_search,'_',$url_);
			$url_file .= ".json";
	        $json = "$url_file";
			
			// instead of reparsing the website,
			// check if we have a local cache of the content being requested
			// that is not out of sync with The Namibian by 6 hours
			//
			// issues: if the file contains errors on last update, do we wait 6 hours
			//         before we serve valid data?
			//
			define('MAX_CACHE_LIFE',2);
			define('CACHE_ON',true);
			
			if (CACHE_ON && file_exists($json) && is_readable($json)){
			    // check that its not empty 
				if (filesize($json)>0){
                    if (hours_last_modified($json) < MAX_CACHE_LIFE){
						// serve the local json encoded copy
						echo file_get_contents($json);
						exit(0);
					}				    	
				}
			}
			
                        /* read server */
                        $html    = file_get_html("$url");
			$arr = array();
			$arr['articles'] = array();
			$idx = 0;
			
			// container: current_story_content
			// heading: story_heading4
			foreach($html->find('#story_heading4') as $element) {	
					$body = "";
					$title = "";
					$date = "";
					$img = "";
					$url = "";
					$fullstory = "";
					$author = "";
					$imagetitle = "";

					$title = $element->innertext; 
					$date = $element->nextSibling()->nextSibling()->plaintext;	
					if (strlen($date) != strlen("2015-07-17"))
					{
						// wrong, we have the story as the date
						$date = $element->previousSibling();//->plaintext();
						$date = $date->plaintext;
					}
					
					$url = $element->outertext; 
					
					$start = strpos($url,'href=',0);
					$url = substr($url,$start+strlen("href=")+1);
					$url = explode("detail",$url)[0] . 'detail';

					/*
					$url   = $element->find('#story_heading4',0);
					$date  = $element->find('#story_cat',1);
					$date = $element->find('#story_cat',0)->plaintext;
					$body = $element->find('#story_text',0)->plaintext;
					*/
					$image = "";			
					$img = $html->find("a[href='$url']",0);	
					
					// sometimes the body is found in child 0
					if ($img) {
						$body = $img->nextSibling()->plaintext;												
						$image = "$siteurl".$img->children(0)->src;	
						$fullstory = "";					
					}

					// sometimes the body is found in child 1
					if (!$body){
						$body = $element->nextSibling()->nextSibling()->nextSibling()->nextSibling()->plaintext;
					}
					
					// we dont want a blank image
					if (!$image){
						$image = $_SERVER['SERVER_NAME'] . $_SERVER['PHP_SELF'];
						$file =$_SERVER['SCRIPT_NAME'];
						$file = substr($file, strrpos($file, '/') + 1);
						$image = /*"http://" . str_replace($file,"",$image ) .*/ "noimage.png";
					}
					
					// get the full story
					if ($url){
					    $url_fs = $siteurl . $url;
						// get content
						 $html_fs = file_get_html($url_fs);
						 foreach($html_fs->find('#current_story_content') as $elem)
						 {
							 $i = 0;
							 $cat = "";
							 
							 foreach($elem->find('#story_cat') as $prop){
								 switch ($i)
								 {
									case 0: 
									      $cat = "title"; 
										  //"$cat: " . $prop->plaintext . "<BR>";
										  break;
										  
									case 1: 
									      $cat = "author"; 
										  $author = $prop->plaintext;
										  $author_ =strtolower($author);
										  if (substr($author_,0,3) == 'by '){
										      $date .= " | $author";
										  }
										  else
										  {
											  // if we dont have an author,
											  // this becomes the image title
											  $imagetitle = $author;
										  }
										  break;
										  
									case 2: 
									       $cat = "image title"; 
										   if (!$imagetitle) 
											    $imagetitle = $prop->plaintext;
										   break;
										   
									default: 
											$cat = "";
											break;
								 }
								 
								 $i++;
							 }		
							 
							 // get full story
							 foreach($elem->find('#story_text') as $prop){
								  $fullstory .= nl2br($prop->plaintext);
							 }	
						 }
					}
					
					$article = ["article"=>["title"=>$title,
											  "info"=>$date,
											  "image"=>$image,
											  "body"=>$body,
											  "imagetitle"=>$imagetitle,
											  "fullstory"=>$fullstory,
											  "url"=>$url,
											  "index"=>$idx]
								];
					$idx++;
					
					$arr["articles"][] = $article;
			}			
			
			// cache this copy
			if (CACHE_ON)
				file_put_contents($json,json_encode($arr));
			
			// serve
			echo json_encode($arr);
			break;
  }  
  
	function curl_post($url, $post, $flag_post){
			global $curlerror;

			$ch = curl_init();

			$postData = "";
			foreach( $post as $key => $val ) {
				$postData .=$key."=".$val."&";
			}
			$postData = rtrim($postData, "&");

			curl_setopt($ch,CURLOPT_URL,$url . "?$postData");
			curl_setopt($ch,CURLOPT_POST, $flag_post); //0 for a get request
			curl_setopt($ch,CURLOPT_POSTFIELDS, $postData);
			curl_setopt($ch,CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch,CURLOPT_CONNECTTIMEOUT ,3);
			curl_setopt($ch,CURLOPT_TIMEOUT, 20);
			$response = curl_exec($ch);
			$currerror = curl_error($ch);
			curl_close ($ch);

			return $response;
	}    
	
	/*
	 * returns the hours a file was modified
	 */
	function hours_last_modified($filename){
             if (!file_exists($filename))
				 return 0;
			 
			$date_current = date("Y-m-d H:i:s");
			$date_modified = date("Y-m-d H:i:s", filemtime($filename));


			$day1 = strtotime($date_modified);
			$day2 = strtotime($date_current);

			$diffHours = round(($day2 - $day1) / 3600);

			return $diffHours;	
	}
?>
