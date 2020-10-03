
function runQuery(preview) {
    var formdata = $("#export-repeating").serializeArray();

    if (configurationError(formdata)) {
        return;
    }

    var json = getExportJson(preview, formdata);
    clearError();
    $("#longop-running").show();
    $.ajax({
        url: $("#report-submit").val(),
        data: json,
        timeout: 60000000,
        type: 'POST',
        dataType: 'json',
        success: function (response) {
            $("#longop-running").hide();
            if (response.status === 0) {
                showError("Error: " + response.message);
            } else {
                if (preview) {
                    var data = tableize(response.headers, response.data);
                    //console.log(data);
                    $("#preview-table-div").replaceWith(data);
                    $('#preview-table').DataTable();
                    $("#datatable").show();
                } else {
                    var data = convertToCSV(response.data);
                    triggerDownload(data, json.reportname + ".csv", 'text/csv;charset=utf-8;' )
                }
            }

        },
        error: function (request, error) {
            $("#longop-running").hide();
            showError("Server Error: " + JSON.stringify(error));
        }
    });
}

function configurationError(formdata) {

    var errorFound = false;
    formdata.forEach(function (item, index) {
        if ( (item.name.startsWith('lower-bound') || item.name.startsWith('upper-bound') ) ) {
            if ( isNaN(item.value) === true) {
                showError("non-numeric characters found in input string '" + item.value);
                errorFound = true;
            }
        }
    });

    if ( $(".badge-danger:visible").length > 0) {
        showError("Please ask your REDCap administrator to configure this project with @PRINCIPAL_DATE " +
            "and/or @FORMINSTANCE action tags as per the <a href='https://github.com/susom/redcap-em-export-repeating-data'>documentation for this module</a>");
        errorFound = true;
    }
    return errorFound;
}

function clearError() {
    $( "#data-error" ).hide();
}

function showError(message) {
    $( "#datatable" ).hide();
    $("#data-error-message").replaceWith('<div id="data-error-message"  class="alert alert-danger mt-5" >' + message + '</div>');
    $( "#data-error" ).show();
}

function tableize(headers, rows) {
    // datatable.net seems to expect full on <table><tr><td></td></tr></table> markup
    var table = '<div id="preview-table-div"><table id="preview-table" class="display"><thead>';
    headers.forEach(function (header, index) {table +=  '<th>' + header + '</th>';});
    table += '</thead><tbody>'
    rows.forEach(function (row, index) {
        table += '<tr>'
        row.forEach(function(cell, index) {
            table +=  '<td>' + cell + '</td>';
        });
        table += '</tr>'
    });
    table += '</tbody></table></div>'
    return table;
}

function tableize_col(col, index) {
    return '<td>' + col + '</td>';
}

function saveExportJson() {
    var json = getExportJson(false);
    triggerDownload(json, json.reportname + ".json", 'text/json;charset=utf-8;' )
}

function getExportJson(is_preview, formdata) {
    var struct = {};
    struct.reportname = 'unnamed_report';
    var columns =[];
    $('.column-selector').each(function() {
        if ($( this ).prop( 'checked' )) {
            var table = {};
            table.instrument = $(this).closest('.panel').attr('id').substr(6);
            table.field = $(this).prop('id');
            columns.push(table);
        }
    });

    // ok, now that we have the column specification, build up the filters.
    // the inputs generated by the REDCap API call have a quirky naming convention
    // process the results in the order returned, looking for these four names:
    // field_name, limiter_operator[], limiter_value[], and limiter_connector[]
    // we get back an array of objects with name: and value: as the two properties
    // this is the target format:
    //        "filters": [{
    //                 "instrument": "person",
    //                 "field": "record_id",
    //                 "operator": "equals",
    //                 "param": "1",
    //                 "boolean": "AND"
    //             }

    var filters = [];
    var filter;
    var join = [];

    formdata.forEach(function (item, index) {

        if (item.name === 'instrument') {
            filter = {};
            filter.instrument = item.value;
        } else if (item.name === 'field_name') {
            filter.field = item.value;
        } else if (item.name === 'limiter_operator[]') {
            filter.operator = item.value;
        } else if (item.name === 'limiter_value[]') {
            filter.param = item.value;
        } else if (item.name === 'limiter_connector[]') {
            filter.boolean = item.value;
            filters.push(Object.assign({}, filter));
        } else if (item.name === 'report_name') {
            if (item.value.length > 0) {
                struct.reportname = item.value;
            }
        } else if (item.name.startsWith('lower-bound')) {
            instrument_name = item.name.substr(12);
            if (! join[instrument_name]) {
                join[instrument_name] = {};
            }
            join[instrument_name].lower_bound = item.value;
        } else if (item.name.startsWith('upper-bound')) {
            instrument_name = item.name.substr(12);
            if (! join[instrument_name]) {
                join[instrument_name] = {};
            }
            join[instrument_name].upper_bound = item.value;
        }
    });

    // assemble the list of visible panels, in order, with their status
    $(".panel:visible").each(function() {
        instrument_name= $(this).attr('id').substr(6);
        if (! join[instrument_name]) {
            join[instrument_name] = {};
        }
        var panelHeading = $(this).find(".panel-heading");
        if (panelHeading.hasClass('tier-1')) {
            join[instrument_name].join = 'singleton'
        } else if (panelHeading.hasClass('tier-2')) {
            join[instrument_name].join = 'repeating-primary'
        } else if (panelHeading.hasClass('tier-3')) {
            join[instrument_name].join = 'repeating-instance-select'
        } else if (panelHeading.hasClass('tier-4')) {
            join[instrument_name].join = 'repeating-date-pivot'
        }
    });
    struct.preview = is_preview ;
    struct.project = "standard";
    struct.columns = columns;
    struct.filters = filters;
    struct.join = join;
    // console.log(struct);
    return struct;

}

function convertToCSV(objArray) {
    var array = typeof objArray != 'object' ? JSON.parse(objArray) : objArray;
    var str = '';

    for (var i = 0; i < array.length; i++) {
        var line = '';
        for (var index in array[i]) {
            if (line != '') line += ','
            if (array[i][index] && array[i][index].includes(",")) {
                line += '"';
            }
            line += escape_doublquotes(array[i][index]);
            if (array[i][index] && array[i][index].includes(",")) {
                line += '"';
            }
        }

        str += line + '\r\n';
    }

    return str;
}

function escape_doublquotes(data) {
    if (!data) return data;
    return data.replace(/["]/g, '""');
}

function triggerDownload(data, filename, filetype) {
    var blob = new Blob([ data ], {type: filetype});
    if (navigator.msSaveBlob) { // IE 10+
        navigator.msSaveBlob(blob, filename );
    } else {
        var link = document.createElement("a");
        if (link.download !== undefined) { // feature detection
            // Browsers that support HTML5 download attribute
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", filename );
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }
}
