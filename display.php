<?php
//
/** @var \Stanford\ExportRepeatingData\ExportRepeatingData $module */


$files = $module->processFiles();
?>
<div class="container">
    <?php
    if ($files) {
        ?>
        <table class="table table-bordered">
            <thead>
            <tr>
                <th>File</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php
            foreach ($files as $file) {
                if ($file['status'] == 'available') {
                    ?>
                    <tr>
                        <td><?php echo $file['path'] ?></td>
                        <td><a target="_blank"
                               href="<?php echo $module->getUrl("server-dl/download.php") . '&file=' . $file['path'] ?>">Download</a>
                        </td>
                    </tr>
                    <?php
                } elseif ($file['processing']) {
                    ?>
                    <tr>
                        <td><?php echo $file['path'] ?></td>
                        <td>Processing</td>
                    </tr>
                    <?php
                }
            }
            ?>
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
