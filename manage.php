<?php
//
/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */


$reports = json_decode($module->getProjectSetting('saved-reports'), true);

?>
<script src="<?php echo $module->getUrl("client/js/controller.js") ?>"></script>
<script>
    window.onload = setTimeout(function () {
        loadSavedReportSettings();
    }, 100)
</script>
<input type="hidden" name="save-report" id="save-report"
       value="<?php echo $module->getUrl("server/manageReports.php") ?>">
<div class="container">
    <?php
    if ($reports) {
        ?>
        <table class="table table-bordered">
            <thead>
            <tr>
                <th>File</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody id="saved-reports-tbody">
            </tbody>
        </table>
        <?php
    } else {
        ?>
        <div class="alert">No Saved Reports for this Projects</div>
        <?php
    }
    ?>
</div>
