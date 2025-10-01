<?php
namespace Stanford\TimezoneScheduler;

use DateTime;
use DateTimeZone;
/** @var TimezoneScheduler $module */

?>

<h3>Timezone Scheduler Admin Page</h3>
<p>This is currently a testing area for development -- in the final version we could put documentation or, potentially,
    we could build a better user interface for managing the module settings.</p>
<hr/>

<?php



// Replace this with your module code
echo "Hello from $module->PREFIX";


echo "<br/>";
echo $module->getUrl("pages/cancel.php", true);


// $p = $module->getProjectSetting('timezone-database');
// echo "<br/>Project Setting timezone-database: " . ($p ? $p : "not set");
// $q = $module->getTimezoneList();
// echo "<br/>Timezone List count: " . count($q);
// echo "<pre>";
// print_r($q);
// echo "</pre>";


if ($module->isAuthenticated() && $module->getUser()->hasDesignRights()) {
    echo "<br/>User is authenticated and has design rights.";
} else {
    echo "<br/>User is NOT authenticated or does NOT have design rights.";
}

if($module->isAuthenticated()){
    echo "<br/>Is Authenticated!";
    $user = $module->getUser();
    $module->emDebug("User is authenticated: ", $user, $user->hasDesignRights());
    // $rights = $module->getRights($user->getU)
}

exit();


$dtz = new DateTimeZone('America/New_York');
$dt = new DateTime("now", $dtz);
$offset = $dtz->getOffset($dt); // Offset in seconds
$abbreviation = $dt->format('H:i T'); // Timezone abbreviation, e.g., PST or CET
echo "$tz | Offset: $offset | Abbreviation: $abbreviation\n";
$dt->setTimezone(new DateTimeZone('Europe/Prague'));
$offset = $dtz->getOffset($dt); // Offset in seconds
$abbreviation = $dt->format('H:i T'); // Timezone abbreviation, e.g., PST or CET
echo "<br/>$tz | Offset: $offset | Abbreviation: $abbreviation\n";


print "<pre>";
print_r($dtz);
print "<br/>DateTimeZone::listIdentifiers(DateTimeZone::ALL):<br/>";
print_r(DateTimeZone::listIdentifiers(DateTimeZone::ALL));
print "<br/>DateTimeZone::listAbbreviations():<br/>";
print_r(DateTimeZone::listAbbreviations());
echo "</pre>";




// $module->load_module_config();


// // $tz = $module->filter_tz_config("test_form",88);
// $tz = $module->filter_tz_config("form2",88);

// echo "<pre>";
// print_r($module->config);
// echo "=====";
// print_r($tz);
// echo "</pre>";

// Use DateTimeZone;
// $timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
// echo "<pre>";
// print_r($timezones);
// echo "</pre>";

//file_put_contents('timezones.json', json_encode($timezones, JSON_PRETTY_PRINT));
