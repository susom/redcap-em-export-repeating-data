# Export Repeating Data External Module

### What does it do?
Native support for data reporting and export in REDCap does not work
particularly well with repeating forms data. 

Here are the reporting 
scenarios to consider:
1. All data in the report belongs to singleton (non-repeating) forms. 
There is one row in the report for each record in REDCap. The behavior in this
case is the same in this EM as in the built-in REDCap data export. <br/>  
<img src="./documentation/assets/Scenario_1.png" width="600"/>
1. The report includes data from one or more singleton forms but also includes
data from just one repeating form. REDCap's built-in data export format
places the data from the repeating forms on separate rows
from the data in the singleton form(s), i.e. if you are exporting
one record with two repeating form instances, there will be three
rows in the report: one with the data from the singleon form,
and two for the data from the repeating form instances,
with blanks liberally sprinkled throughout. By contrast, this EM produces a
densely populated report
in which there is one row per repeating form instance, with
the singleton form field values repeated in-line (this process is known as "de-normalization":"). 
So if are exporting
one record with two repeating form instances, there will be only
two rows, completely filled in with data, and both rows will have
the same information for the corresponding singleton form fields. <br/>
For example, we could have a singleton form capturing patient information such as patient name,
and a repeating form used to capture diagnoses.    <br/>
<img src="./documentation/assets/Scenario_2.png" width="700"/>
2. One or more repeating instruments with optional 
singleton instruments, where the repeating instruments are linked with
the [Instance Select EM](https://github.com/lsgs/redcap-instance-select). 
Any singleton values are copied into each row,
and fields from linked repeating instruments are copied into the row 
they correspond with.    <br/>
<img src="./documentation/assets/Scenario_3.png" width="650"/>
3. One or more repeating instruments with optional singleton
instruments, where the repeating instruments are *not* linked. 
If both forms have date fields, a repeating instrument can be designated
as the ‘primary’ for reporting purposes. Fields from other repeating instruments can be added to the 
report by selecting matching rows by date proximity.    <br/>
For example, you might have a set of datestamped diagnoses
and a companion set of datestamped lab results. You could craft
a report of all diagnoses with the closest associated lab result
by selecting the diagnosis instrument as the primary, then supplying 
date ranges for allowable association of a lab result when prompted to do so by the UI.
In the case where multiple results fall within the specified time frame,
the closest one is selected. In the case of a tie, the tie is broken arbitrarily.</br>
<img src="./documentation/assets/Scenario_4.png" width="700"/>


### Recommended Companion EMs
As described in Scenario 3 above, this EM works very nicely in conjunction with 
 [Instance Select EM](https://github.com/lsgs/redcap-instance-select), though its use is optional.

Also note that projects with linked repeating data (aka "relational" data) frequently find it convenient
to conduct data entry for related records from inside the
context of the parent instrument. This workflow is nicely supported
by the [Instance Table EM](https://github.com/lsgs/redcap-instance-table),
a companion EM to "Instance Select". The "Instance Select" and "Instance Table" EMs work well 
together; a project that uses them both in conjunction with this reporting EM can produce
a plausible approximation of an underlying relational database.

Last Updated: 09/29/2020 12:57pm
