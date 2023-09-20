<?php

	include_once('srtm_batch.php');

	$request = file_get_contents('php://input');
	
	if($request) {
		$gs_srtm = new srtm_batch();
		$input = json_decode($request, true);
		//print_r ($input);
		//exit;
	}
	else {
		echo "Invalid Request";
		exit;
	}	
	
	$elevations = array();
	
	foreach($input AS $key=>$value) {
		$lat = $input[$key]['x'];
		$lng = $input[$key]['y'];
		$elevations[] = array("lat"=>$lat, "lng"=>$lng, "elevation"=>$gs_srtm->get_elevation($lat, $lng));
	}
	
	echo json_encode($elevations);


?>