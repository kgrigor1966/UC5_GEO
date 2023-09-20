<?php
include_once('google_functions.php');

class weather {
	
	private $con;
	private $lang;

	public function __construct($con, $lang) {
		$this->con = $con;
		$this->lang = $lang;
		include_once('./res/' . $this->lang . '/functions/get_city_name.php');
		include_once('./res/' . $this->lang . '/functions/grammars.php');
		include_once('./res/' . $this->lang . '/' . $lang . '.php');
	}

	private function getbf($wind_speed) {
		$bf=round(pow(($wind_speed*1000/3600)/0.836,2/3),0);
		return $bf;
	}
	
	
	
	private function degToCompass($wind_direction_degrees) {
	$val = floor(($wind_direction_degrees / 22.5) + 0.5);
    
	$cardinal_directions = array("N", "NNE", "NE", "ENE", "E", "ESE", "SE", "SSE", "S", "SSW", "SW", "WSW", "W", "WNW", "NW", "NNW");
	
	return $cardinal_directions[$val % 16];
}

	
	public function fweather($answer, $userlatlng, $ident, $userlogid, $lingubot_vm, $uid='') {
		//echo $answer;
		$parseBot = explode("^^^", $answer);
		$answerFromBot = $parseBot[0];
		$dlag = $parseBot[1];
		$city = trim(city_normalizer($parseBot[2]));

		$get_city_name = new get_city_from_string($this->con);
		$city = $get_city_name->starts_with_upper($city, $this->lang);
		
		$dayName = $parseBot[3];
		
		if(function_exists("articles_remover")) {
			$dayName = trim(articles_remover($dayName));
		}
		
		$dayName = trim(preg_replace('!\s+!', ' ', $dayName)); //replace multiple spaces with single one
		
		if($this->lang=='pt') {
			$dayName = str_replace(" ","-",$dayName);
		}
		
		if($this->lang == 'en') {
			$lstTwo = substr($dayName, -2);
			if($lstTwo == "'s") {
				$dayName = substr($dayName, 0, -2);
			}
		}
		/* convert day name first letter to capital (depricated)
		$dayName = mb_convert_case($dayName, MB_CASE_TITLE, "UTF-8");
		*/
		
		if(isset($parseBot[4])) {
			$certain_weather_condition_query = $parseBot[4];
		}
		$translations = translations();

		
		//*** we have city, let's find it's coordinates ***//
		if(isset($city) && $city!="") {
			
			/*first check into database*/
			$getCity = get_city($city, $this->con, $this->lang);

			//city found in database
			if($getCity!='fail') {
				$location = explode(";", $getCity);
				$coordinates = $location[0];
				$w_city = $location[1];
				$region = $location[2];
				$country = $location[3];
			}

			//city not found in database, let's ask google for it
			else {
				
				//$city_details = get_coordinates($city, $this->lang); //google
				$city_details = get_coordinates_OSM($city, $this->lang); //nominatim
				
				if($city_details!="ZERO_RESULTS"){
				
					list($city_lat, $city_lng, $country, $region, $city_name) = explode(",", $city_details);
				
					$coordinates = $city_lat . "," . $city_lng;
				
					$w_city = $city_name;

					list($citylat, $citylng) = explode(",", $coordinates);

					$sql = $this->con->prepare("INSERT INTO cities (city_name_asked, city_name, lat, lng, prerfecture, country, language) VALUES(?,?,?,?,?,?,?)");
					
					$sql->execute(array(trim($city), $city_name, $citylat, $citylng, $region, $country, $this->lang));
				}
				//city not found definitively
				else {
					$city = '';
				}
			}
		}
		
		//user not asked for specific city, so show the weather for his current position	
		if(isset($city) && $city == '') {
		//else {
			list($user_lat, $user_lng) = explode("," , $userlatlng);
			
			$coordinates = $userlatlng;			
			
			$city_name = '';
			$city_name = osm_reverse_geocode($user_lat, $user_lng, $this->lang);
			$w_city = $city_name;
			
			/*
			//if we have greek try to use mls mini reverse geocode
			if($this->lang == 'el') {
				$city_details = mls_reverse_geocode($user_lat, $user_lng, $this->lang);
			}
			else {
				$city_details = "ZERO_RESULTS";
			}	
			
			//echo $city_details;
			if($city_details=="ZERO_RESULTS"){
				$city_details = get_coordinates_latlng($coordinates, $this->lang);  // mls zero results, so ask google
			}
			
			if($city_details!="ZERO_RESULTS"){
			
				list($country, $region, $city_name) = explode(",", $city_details);
			
				$w_city = $city_name;
			}*/
		}
		
		//user asked the weather for a specific day of the week
		$locale = locale();
		
		$dayNameTemp = $dayName;
		//echo "lala " . $dayName;
		
		if(array_key_exists($dayName, $locale)) {	
			$dayName_en = $locale[$dayName];
			//echo ($dayName_en);
			$day_of_week = date('N', strtotime($dayName_en));
			$current_week_day = date('N');
			
			if($day_of_week>=$current_week_day) {
				$dlag = $day_of_week - $current_week_day;
			}
			else {
				$dlag = 7-($current_week_day-$day_of_week);
			}		
		}
		
		$days_lag = array();
		$days_lag[0] = $dlag;

		if(function_exists('weather_weekend_dlag_increment')) {
			$weather_weekend_dlag_increment = weather_weekend_dlag_increment();
			if(array_key_exists($dayName, $weather_weekend_dlag_increment)) {	
				$numOfDays = $weather_weekend_dlag_increment[$dayName];
				for($i=1; $i<$numOfDays+1;$i++) {
					$days_lag[$i] = $days_lag[0]+$i;
				}
			}
		}

		//print_r($days_lag);
		// get weather data
		$weather_response = $this->weatherData($this->lang, $coordinates, $uid, $lingubot_vm); 

		if($weather_response=="ZERO_RESULTS") {
			$this->weather_misfire('weather_no_results', $ident, $userlogid, $lingubot_vm);
		}	
		
		$service_array_list = json_decode($weather_response);
		$service_array = json_decode($weather_response, true);
				
		//check if weather json is empty
		function array_filter_recursive($input) { 
			foreach ($input as &$value) { 
				if (is_array($value)) { 
					$value = array_filter_recursive($value); 
				} 
			} 
			return array_filter($input); 
		} 
		
		$errors = array_filter_recursive($service_array);

		if(empty($errors)) {
			$this->weather_misfire('weather_no_city_found', $ident, $userlogid, $lingubot_vm);
		}
		
		if(function_exists('wind_directions_entolocal')) {
			$wind_directions = wind_directions_entolocal();
		}
		
		if(function_exists('wind_temperatures')) {
			$wind_temperatures = wind_temperatures();
		}
		
		if(function_exists('day_articles')) {
			$day_articles = day_articles();
		}
		
		$list_ = $service_array_list->forecast;
		
		if($w_city == $country) {
			$w_city = $region;
		}
		
		if(empty($w_city)) {
			$w_city = $service_array_list->location->region;
		}
		
		$answer = $answerFromBot . $w_city . ". ";
		$tts = $answerFromBot . $w_city . ". ";

		if($this->lang == 'tr') {
			$answer = $answerFromBot . $w_city . " için. ";
			$tts = $answerFromBot . $w_city . " için. ";
		}
		
		if($dlag<7) {
			
			foreach($days_lag as $dlagsKey=>$dlagsValue) { 
				$wi = -1;
				foreach ($list_ as $key=>$value) {
					$wi++;
					$dlag = $dlagsValue;
					
					if($wi==$dlag) {
						
						$weekday_ = $value->weatherDesc[0]->weekday;
						//echo $weekday_;
						if($this->lang=="sr") {
							$weekday_ = transliteration_cyrillic($weekday_, 'cyrlat');
						}
						
						if(function_exists('day_names_for_tts')) {
							$day_names_for_tts = day_names_for_tts();
							if(array_key_exists($weekday_, $day_names_for_tts)) {	
								$weekday_ = $day_names_for_tts[$weekday_];
							}
						}
						
						if($dlag==0) {
							$weekday_ph = $translations['the_weather'] . " " . $translations['today'];
							if($this->lang == 'tr') {
								$weekday_ph = $translations['the_weather'];
							}
						}
						else {
							if($this->lang == 'tr') {
								$weekday_ph = $translations['the_weather'] . " " . $weekday_ . " gününü";
							}
							elseif($this->lang=='he') {
								$weekday_ph = $translations['the_weather'] . " " . $day_articles[$weekday_] . $weekday_;
							}
							else {
								$weekday_ph = $translations['the_weather'] . " " . $day_articles[$weekday_] . " " . $weekday_;
							}
						}	
						
						if(empty($city)) {
							$teleia='';
						}
						else {
							$teleia=': ';
						}
						
						if($this->lang == 'el' || $this->lang == 'tr') {
							$windSpeed = $this->getbf($value->windspeedKmph);
							$windSpeedUnit = $translations['beaufort'];
						}
						else {
							$windSpeed = $value->windspeedKmph;
							$windSpeedUnit = $translations['kph'];
						}
						
						if($this->getbf($value->windspeedKmph)==0) {
							$winds_txt = $translations['winds_apnea'];
						}
						else {
							if($this->lang == 'tr') {
								$winds_txt= $translations['Winds'] . " " . $wind_directions[$value->winddirection] . ", " . $windSpeedUnit . " " . $translations['wind_bft_scale'] . " " . $windSpeed . ".";
							}
							else {
								$winds_txt= $translations['Winds'] . " " . $wind_directions[$value->winddirection] . ", " . $translations['wind_bft_scale'] . " " . $windSpeed . " " . $windSpeedUnit . ".";
							}
						}	
										
						$tempMinC = (int)$value->tempMinC;
						$tempMaxC = (int)$value->tempMaxC;
						
						$tempMinC_answ = $tempMinC;
						$tempMaxC_answ = $tempMaxC;
						
						//** check if we have minus temperature **/
						$temp_Min_extra_text = ' ';
						$temp_Max_extra_text = '';
						
						if($tempMinC<0) {
							$temp_Min_extra_text = $translations['temperature_minus'];
							$tempMinC = -1*$tempMinC;
						}
						
						if($tempMaxC<0) {
							$is_temp_Max_minus = true;
							$temp_Max_extra_text = $translations['temperature_minus'];
							$tempMaxC = -1*$tempMaxC;
						}

						$degreesC_translation = $translations['degrees_celsius'];
						
						if(function_exists("numbers_falls")){//mostly for sr
							$degreesC_translation_key = 'degrees_celsius' . numbers_falls($tempMaxC);
							$degreesC_translation = $translations[$degreesC_translation_key];
						}
						
						//echo "lala" . $degreesC_translation ;
						//echo $translations['degrees_celsius'];
						/* added because in some languages we need extra handling for temperature nums. eg for romanian if the temperature is greater than 19 then we add the article "de" before the number*/
						$article_temperature = ' ';
						if(function_exists("articles_temperatures")) {
							//$tempMaxC = articles_temperatures($tempMaxC);
							$article_temperature = articles_temperatures($tempMaxC);
							//echo $article_temperature;
						}
												
						if(array_key_exists($tempMinC, $wind_temperatures)) {
							$tempMinC = $wind_temperatures[$tempMinC];
						}	
						
						if(array_key_exists($tempMaxC, $wind_temperatures)) {
							$tempMaxC = $wind_temperatures[$tempMaxC];
						}
						
						$tempMaxC = $tempMaxC . $article_temperature; //mostly for romanian
						
						if(!isset($certain_weather_condition_query) || empty($certain_weather_condition_query)) {
							
							$weather_conditions_description = $value->weatherDesc[0]->conditions;
							
							if($this->lang=="sr") {
								$weather_conditions_description = transliteration_cyrillic($weather_conditions_description, 'cyrlat');
							}
							
							$and = $translations['and']; 
							
							if($this->lang == 'pl' || $this->lang == 'tr') {
								$and = $translations['and_weather']; 	
							}
							
							$answer .= $weekday_ph . ". " . $weather_conditions_description . ". " . $translations['temperature_between'] . $tempMinC_answ . " " . $and . " " . $tempMaxC_answ . " " . $degreesC_translation . ". " . $winds_txt . " ";
							
							$tts .= $weekday_ph . ". " . $weather_conditions_description . ". " . $translations['temperature_between'] . $temp_Min_extra_text . $tempMinC . " " . $and . " " . $temp_Max_extra_text . $tempMaxC . " " . $degreesC_translation . ". " . $winds_txt . " ";
							//echo $temp_Max_extra_text;
						}
						else { //user asked for special weather conditions i.e. rain, snow, sun
							
							if($dlag==0) {
								$weekday_ph = $translations['today'];
							}
							else {
								if($this->lang == 'sr') {
									$day_articles_aitiatiki = day_articles_aitiatiki();
									$weekday_ph = $day_articles_aitiatiki[$weekday_];
								}
								elseif($this->lang == 'tr') {
									$weekday_ph = $translations['the_weather'] . " " . $weekday_ . " gününü";
								}
								elseif($this->lang == 'he') {
									$weekday_ph = $translations['the_weather'] . " " . $day_articles[$weekday_] . $weekday_;
								}
								else {
									$weekday_ph = $day_articles[$weekday_] . " " . $weekday_;
								}
							}
							
							$weather_condition = $value->$certain_weather_condition_query;
							
							$forecast_day = $weekday_ph;
							$forecast_temperature_min = $temp_Min_extra_text . $tempMinC;
							$forecast_temperature_max = $temp_Max_extra_text . $tempMaxC . " " . $degreesC_translation;
							$forecast_wind = $this->getbf($value->windspeedKmph);
							$forecast_rain = "-";
							$forecast_snow = "-";
							$forecast_sunny = "-";

							switch ($certain_weather_condition_query) {
								case "rain":
									if($weather_condition==0) {
										$forecast_rain = 0;
									}
									if($weather_condition==1) {
										$forecast_rain = 1;
									}	
									$new_q = 'weather_forecast_rain';
								break;
								case "snow":
									if($weather_condition==0) {
										$forecast_snow = 0;
									}
									if($weather_condition==1) {
										$forecast_snow = 1;
									}
									$new_q = 'weather_forecast_snow';
								break;
								case "sun":
									if($weather_condition==1) {
										$forecast_sunny = 1;
									}
									if($weather_condition==0) {
										$forecast_sunny = 0;
									}
									$new_q = 'weather_forecast_sunny';
								break;
								case "wind":
									$new_q = 'weather_forecast_wind';
									$forecast_wind = $windSpeed  . " " . $windSpeedUnit;
								break;
								case "temperature":
									$weather_temperature = $this->current_temperature($coordinates);
									
									$time_modifier = time_modifier();
									$array_to_use = $wind_temperatures;
									
									if($this->lang == 'pl') {
										$array_to_use = $time_modifier;
									}
									
									if(array_key_exists($weather_temperature, $array_to_use)) {
										$weather_temperature_word = $array_to_use[$weather_temperature];
									}
									else {
										$weather_temperature_word = $weather_temperature;
									}
									
									$new_q = 'weather_forecast_temperature';
									
									//if($dlag == 0 && $this->lang=='el') {
									if($dlag == 0) {
										
										$new_q = $translations['repeat_sentence'] . ' ' . $weather_temperature_word . ' ' . $degreesC_translation . '.';

										//special
										$response = $this->weather_misfire_special($new_q, $ident, $userlogid, $lingubot_vm);
										
										if(array_key_exists($weather_temperature, $array_to_use)) {
											$response['a'] = preg_replace('/\b' . $weather_temperature_word . '\b/u', array_search($weather_temperature_word, $array_to_use), $response['a']);
										}
										
										return $response;
									}
									
								break;
							}
							
						//	echo "lala" . $forecast_wind;//$new_q . " - " . $weather_extra_params_to_post;
							if($forecast_wind == 1) $forecast_wind = 2;
							/* call weather_extras.php */
							$weather_extra_params_to_post = $forecast_day . "@" . $forecast_temperature_min . "@" . $forecast_temperature_max . "@" . (string)$forecast_wind . "@" . $forecast_sunny . "@" . $forecast_rain . "@" . $forecast_snow; 
							
							//echo $weather_extra_params_to_post;
							$weather_extras = new weather_extra_params();
							
							$response = $weather_extras->weather_post_extra_params($new_q, $ident, $userlogid, $lingubot_vm, $weather_extra_params_to_post);
							
							$tts .= " " . $response['tts'];
							//echo mb_detect_encoding($tts);
						}
						break;
					}
				}
			}	
			//echo $tts;	
		}
		else {
			$this->weather_misfire('weather_out_of_range', $ident, $userlogid, $lingubot_vm);
		}	
		
		$service_array['location']['user'] = $w_city;
		$service_array['location']['region'] = $region;
		$service_array['location']['area'] = $country;
		$service_array['location']['country'] = $country;
		$service_array['dlag'] = (int)$dlag;
		
		$tts = preg_replace('!\s+!', ' ', $tts); //replace multiple spaces with single one
		$tts = trim($tts);
		$answer = preg_replace('!\s+!', ' ', $answer); //replace multiple spaces with single one
		$answer = trim($answer);
		
		return array('a'=>$answer, 'tts'=>$tts, 'service_data_'=>$service_array);
	}
	
	
	private function weatherData($lang_in, $location, $uid='', $lingubot_vm=0) {
		include_once("weather/wundergound_wconditions_bot.php");

		$weather_conditions = weather_conditions_virtualcrossing();
		$location = trim($location);
		
		$weather_url = 'https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/timeline/'.$location.'/next7days?unitGroup=metric&key=QTWEZSKP925YQ2JW24XR264R9&contentType=json&IconSet=icons2';
				
		$weather_data = @file_get_contents($weather_url);
		$weather_data_arr = json_decode($weather_data,true);

		//$weather_data_arr = $weather_data_arr ['days'];
			
		if(!isset($weather_data_arr) || !is_array($weather_data_arr) || empty($weather_data_arr)) {
			return "ZERO_RESULTS";
		}
		
		$weather_results = $weather_data_arr;
		$weather_results_forecast = $weather_data_arr ['days'];
		$weather_results_current = $weather_data_arr ['currentConditions'];

		$weather_description_code_current = $weather_results_current['icon']; //partly-cloudy-day
		$weather_description_current = $weather_conditions[$weather_description_code_current];
		$weather_icon_current = $weather_description_code_current;
		
		$json_out = '
		{
		"location":{
		"area":"",
		"region":"",
		"country":""
		},';

		$rain = "0";
		$snow = "0";
		$sunny = "0";

		$wu_weather_conditions_rain = array('thunder-rain', 'thunder-showers-day', 'thunder-showers-night', 'rain', 'showers-day', 'showers-night');
		$wu_weather_conditions_snow = array('snow', 'snow-showers-day', 'snow-showers-night');
		$wu_weather_conditions_sun = array('clear-day');


		$json_out .= '
		"current":{
		"sunrise":"' . $weather_results_current['sunrise'] . '",
		"sunset":"' . $weather_results_current['sunset'] . '",
		"observation_time":"' . $weather_results_current['datetimeEpoch'] . '",
		"temp_C":"' . $weather_results_current['temp']. '",
		"temp_F":"' . ($weather_results_current['temp']*1.8+32) . '",
		"weatherCode":"' . $weather_results_current['icon'] . '",
		"rain":"' . $rain . '",
		"snow":"' . $snow . '",
		"sun":"' . $sunny . '",
		"weatherIconUrl":[
		{
		"value":"' . $weather_results_current['icon'] . '"
		}
		],
		"weatherDesc":[
		{
		"value":"' . $weather_description_current . '"
		}
		],
		"windspeedMiles":"' . ($weather_results_current['windspeed']/1.609) . '",
		"windspeedKmph":"' . $weather_results_current['windspeed'] . '",
		"winddirDegree":"' . $weather_results_current['winddir'] . '",
		"winddirection":"' . $this->degToCompass($weather_results_current['winddir']) . '",
		"winddir16Point":"' . $weather_results_current['winddir'] . '",
		"humidity":"' . $weather_results_current['humidity'] . '",
		"pressure":"' . $weather_results_current['pressure'] . '",
		"uvindex":"' . $weather_results_current['uvindex'] . '"
		},
		"forecast":[';

		//forecast
		$weather_code_i = -1;

		if(is_array($weather_results_forecast) && !empty($weather_results_forecast)) {
			foreach ($weather_results_forecast as $key=>$value) {
				$rain_f = 0;
				$snow_f = 0;
				$sunny_f = 0;
				
				$weather_code_i++;
				if($weather_code_i>6)
				{
					break;
				}
				
				$weather_description_code = $weather_results_forecast[$key]['icon'];
				$weather_description = $weather_conditions [$weather_description_code];
				$weather_icon_forecast = $weather_results_forecast[$key]['icon'];
				
				if (in_array($weather_description_code, $wu_weather_conditions_rain)) {
					$rain_f = "1";
				}

				if (in_array($weather_description_code, $wu_weather_conditions_snow)) {
					$snow_f = "1";
				}

				if (in_array($weather_description_code, $wu_weather_conditions_sun)) {
					$sunny_f = "1";
				}

				$week_day_eng = date("l", $weather_results_forecast[$key]['datetimeEpoch']);
				$translations = translations();
				$week_day_local = $translations[$week_day_eng];
				
				$date_sub = 'date';

				$json_out .= 
				'{
				"date":"' . $weather_results_forecast[$key]['datetime'] . '",
				"tempMaxC":"' . $weather_results_forecast[$key]['tempmax'] . '",
				"tempMaxF":"' . ($weather_results_forecast[$key]['tempmax']*1.8+32) . '",
				"tempMinC":"' . $weather_results_forecast[$key]['tempmin'] . '",
				"tempMinF":"' . ($weather_results_forecast[$key]['tempmin']*1.8+32) . '",
				"windspeedMiles":"' . ($weather_results_forecast[$key]['windspeed']/1.609)  . '",
				"windspeedKmph":"' . $weather_results_forecast[$key]['windspeed'] . '",
				"winddirection":"' . $this->degToCompass($weather_results_forecast[$key]['winddir']) . '",
				"winddirDegree":"' . $weather_results_forecast[$key]['winddir'] . '",
				"weatherCode":"' . $weather_results_forecast[$key]['icon'] . '",
				"uvindex":"' . $weather_results_forecast[$key]['uvindex'] . '",
				"rain":"' . $rain_f . '",
				"snow":"' . $snow_f . '",
				"sun":"' . $sunny_f . '",
				"weatherIconUrl":[
				{
				"value":"' . $weather_icon_forecast . '"
				}
				],
				"weatherDesc":[
				{
				"value":"' . $weather_description . '",
				"conditions":"' . $weather_description . '",
				"weekday":"' . $week_day_local . '"
				}
				]
				},';
			}
		}
		$json_out = rtrim($json_out, ',');
		$json_out .=']}';

		$json_out = str_replace ("/", "\/", $json_out);

		return $json_out;
	}
	
		
	private function current_temperature($location) {
		include_once("include/weather/wundergound_wconditions_bot.php");

		$weather_conditions = weather_conditions();
		$location = trim($location);

		$weather_url = 'https://api.aerisapi.com/observations/closest?p=' . $location . '&format=json&radius=1000mi&filter=allstations&limit=1&fields=ob.timestamp,ob.tempC,ob.tempF,ob.weather,ob.weatherShort,ob.weatherPrimaryCoded,ob.feelslikeC,ob.feelslikeF,ob.icon&client_id=45ISJap9ZUg6bBCCczFI4&client_secret=S5DOEwi6is4mWPOcXvmjKd8Q0Ad04dftXQLnBNZ3';
		
		
		$weather_data = @file_get_contents($weather_url);
				
		$weather_data_arr = json_decode($weather_data,true);
		
		if($weather_data_arr['success']!=1 || !$weather_data_arr['success']) {
			return "ZERO_RESULTS";
		}

		$weather_results = $weather_data_arr['response'][0]['ob'];

		$weather_description_code_current = explode(":", $weather_results['weatherPrimaryCoded'])[2];
		$weather_description_current = $weather_conditions[$weather_description_code_current];
		$weather_icon_current = $weather_icon_aerisApi[$weather_description_code_current];
		
		/*$json_out = '
		{
		"location":{
		"area":"",
		"region":"",
		"country":""
		},';

		
		$json_out .= '
		"current":{
		"observation_time":"' . $weather_results['timestamp'] . '",
		"temp_C":"' . (int)round($weather_results['tempC'],0). '",
		"temp_F":"' . (int)round($weather_results['tempF'],0) . '",
		"weatherCode":"' . $weather_results['weatherPrimaryCoded'] . '",
		"weatherIconUrl":[
		{
		"value":"' . $weather_icon_current . '"
		}
		],
		"weatherDesc":[
			{
			"value":"' . $weather_description_current . '"
			}
		]
		}}';*/

		return (int)round($weather_results['tempC'],0);
	}
	
	
	private function weather_misfire($new_q, $ident, $userlogid, $lingubot_vm) {
		/*$error = new misfires();
		$response = $error->error_no_answer($new_q, $ident, $userlogid, $lingubot_vm, $this->lang);
		$tts = $response['tts'];
		
		return array('a'=>$tts, 'tts'=>$tts, 'service_data_'=>array("change_to_noservice"=>true));*/

		$error = new misfires();
		$response = $error->error_no_answer($new_q, $ident, $userlogid, $lingubot_vm, $this->lang, 'json');

		echo $response;
		exit;	
	}
	
	private function weather_misfire_special($new_q, $ident, $userlogid, $lingubot_vm) {
		/*$error = new misfires();
		$response = $error->error_no_answer($new_q, $ident, $userlogid, $lingubot_vm, $this->lang);
		$tts = $response['tts'];
		
		return array('a'=>$tts, 'tts'=>$tts, 'service_data_'=>array("change_to_noservice"=>true));*/	
		
		$error = new misfires();
		$response = $error->error_no_answer($new_q, $ident, $userlogid, $lingubot_vm, $this->lang, 'json');

		echo $response;
		exit;	
	}
}
?>