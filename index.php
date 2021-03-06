<?php include('vendor/autoload.php');
use League\Csv\Reader;
use Carbon\Carbon;

// import gpx
$gpx = simplexml_load_file($argv[1]);
// import accel data
$accel = Reader::createFromPath($argv[2])->fetchAssoc([
	'loggingTime',
	'loggingSample',
	'identifierForVendor',
	'accelerometerTimestamp_sinceReboot',
	'accelerometerAccelerationX',
	'accelerometerAccelerationY',
	'accelerometerAccelerationZ'
]);
// remove first row with names
array_shift($accel);
$accel_count = count($accel);

// new xml document
$output = new SimpleXMLElement("<gpx></gpx>");
$output->addAttribute('version', 1.1);
$output->addAttribute('xmlns', 'http://www.topografix.com/GPX/1/1');
$output->addAttribute('xsi:schemaLocation', 'http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd', 'http://www.w3.org/2001/XMLSchema-instance');
$output->addAttribute('creator', 'gpx-accel-sync');
// copy over attribtues
$meta = $output->addChild('metadata');
$meta->addChild('time', $gpx->time);
$trk = $output->addChild('trk');
$trk->addChild('name', $gpx->trk->name);
$trkseg = $trk->addChild('trkseg');

// loop through each track
foreach($gpx->trk->trkseg->trkpt as $track) {
	// add track point
	$trkpt = $trkseg->addChild('trkpt');
	// copy over elevation, time and coords
	$trkpt->addChild('ele', $track->ele);
	$trkpt->addChild('time', $track->time);
	$trkpt->addAttribute('lat', $track['lat']);
	$trkpt->addAttribute('lon', $track['lon']);

	// convert time to timestamp
	$gps_timestamp = Carbon::parse($track->time);
	$gps_timestamp->setTimezone('America/New_York');

	// loop through accel data as long as the time = this second
	foreach ($accel as $row) {
		// get accel time
		$accel_timestamp = Carbon::parse($row['loggingTime']);

		// if accel time is before gpx time, delete accel time,
		if ($gps_timestamp->diffInSeconds($accel_timestamp, false) < 0) {
			array_shift($accel);
			continue;
		// if next second
		} elseif($gps_timestamp->diffInSeconds($accel_timestamp, false) > 0) {
			break;
		// if same second
		} elseif($gps_timestamp->diffInSeconds($accel_timestamp, false) == 0) {
			// if no accel extension
			if(!$trkpt->extensions) {
				$extensions = $trkpt->addChild('extensions');
			}
			if(!count($trkpt->extensions->children('http://www.garmin.com/xmlschemas/AccelerationExtension/v1'))) {
				$trkpt->extensions->addChild('acc:AccelerationExtension', null, 'http://www.garmin.com/xmlschemas/AccelerationExtension/v1');
			}
			$accel_ext = $trkpt->extensions->children('http://www.garmin.com/xmlschemas/AccelerationExtension/v1');
			// <acc:accel offset="64" x="0.1" y="0.3" z="-0.8" />
			$accel_data = $accel_ext->addChild('acc:accel');
			// ms offset
			$accel_data->addAttribute('offset', $accel_timestamp->format('u')/1000);
			// x,y,z data
			foreach(range('x','z') as $coord) {
				$accel_data->addAttribute($coord, $row['accelerometerAcceleration'.strtoupper($coord)]);
			}
			// remove accell data
			array_shift($accel);
			// show % done
			$pct = floor(count($accel) / $accel_count * 100);
			echo ("$pct% Remaining\r");
		}
	}
}

//save to file
file_put_contents($argv[3], $output->asXML());
