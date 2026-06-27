<?php
namespace Stanford\TimezoneScheduler;

use REDCap;
/** @var TimezoneScheduler $module */

/**
 * Timezone Scheduler Calendar Page
 * - generate an ical feed that can be consumed by google calendar, outlook, etc.
 * - display a calendar view of appointments from this project
 * - calendar entry to include url to record, record id, slot info
 * - if called without a hashed token, display instructions on how to add to calendar apps
 * - have calendar feed refresh as often as permitted by the app (google calendar is every few hours)
 */

$feed_hash = $module->getProjectSetting('ical_feed_hash');


$feed_raw = isset($_GET['feed']) ? $_GET['feed'] : null;
if ($feed_raw) {
    $feed = $module->escape($feed_raw);
    if ($feed !== $feed_hash) {
        http_response_code(403);
        echo "Forbidden: Invalid feed token.";
        exit();
    }

    // Serve the iCal feed
    $module->serveICalFeed($feed);
    exit();
}

// Provide instructions on how to add the feed to calendar apps
// (getIcalFeedUrl() generates & persists the token on first use)
$feedUrl = $module->getIcalFeedUrl();

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
loadJS('Libraries/clipboard.js');
?>
<script type="text/javascript">
    // Copy-to-clipboard action
    var clipboard = new Clipboard('.btn-clipboard');

    $(function(){
        // Copy-to-clipboard action
        $('.btn-clipboard').click(function(){
            copyUrlToClipboard(this);
        });
    });

    function copyUrlToClipboard(ob) {
        // Create progress element that says "Copied!" when clicked
        var rndm = Math.random()+"";
        var copyid = 'clip'+rndm.replace('.','');
        $('.clipboardSaveProgress').remove();
        var clipSaveHtml = '<span class="clipboardSaveProgress" id="'+copyid+'">Copied!</span>';
        $(ob).after(clipSaveHtml);
        $('#'+copyid).toggle('fade','fast');
        setTimeout(function(){
            $('#'+copyid).toggle('fade','fast',function(){
                $('#'+copyid).remove();
            });
        },2000);
    }

</script>


<h3>The Timezone Scheduler iCal Feed</h3>
<p>Use the following feed to add a calendar to Outlook / Google that will show the information
for each scheduled appointment.  The feed will include the record ID field only, so long
as this does not contain PHI, no PHI will be exposed in the calendar feed.</p>

<div style="padding:5px 0px 6px;">
    <div style="float:left;font-weight:bold;font-size:12px;line-height:1.8;">iCal Feed Url:</div>
    <input id="hashurl" value="<?php echo $feedUrl ?>" onclick="this.select();" readonly="readonly" class="staticInput" style="float:left;width:80%;max-width:400px;margin-bottom:5px;margin-right:5px;">
    <button class="btn btn-defaultrc btn-xs btn-clipboard" title="Copy to clipboard" data-clipboard-target="#hashurl" style="padding:3px 8px 3px 6px;"><i class="fas fa-paste"></i></button>
</div>

<?php
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
?>
