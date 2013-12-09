<?php

define("TESTING", FALSE);

define("HCIPA_IDENTIFY_TASK_STEP", 1);
define("HCIPA_SELECT_FUNCTION_STEP", 2);
define("HCIPA_ACCESS_STEP", 3);
define("HCIPA_ENTER_STEP", 4);
define("HCIPA_CONFIRM_STEP", 5);
define("HCIPA_MONITOR_STEP", 6);

function encodeXMLSafe($str)
{
    return htmlspecialchars($str, ENT_QUOTES, "UTF-8");
}

$stepTypeLabels = array(HCIPA_ACCESS_STEP  => "Access ",
                        HCIPA_ENTER_STEP   => "Enter ",
                        HCIPA_CONFIRM_STEP => "Confirm and Save ",
                        HCIPA_MONITOR_STEP => "Monitor ");

function buildFrameName($stepRow)
{
    global $stepTypeLabels;

    return $stepRow["hcipa_step"]
                . "-"  . $stepRow["hcipa_order"]
                . ": " . $stepTypeLabels[$stepRow["hcipa_step"]]
                       . encodeXMLSafe($stepRow["next_user_action"]);
}

define(FINAL_FRAME_NAME, "FINAL FRAME");

// Assumes the database is connected and selected
// The first parameter indicates which Device to export
// The second parameter is an OUT parameter to hold the exported XML string
//     You may decide to echo this string or write it to a file.
// The third parameter decides between using a touchscreen vs. a mouse
// Returns "" on success, a string indicating the type of failure otherwise
// WARNING: At this point, it is assumed that $device is query-safe;
//          that is, it is ok to insert into the query string
//          without fearing a security hole.
function exportHCIPAtoCogTool($device, &$exportedXML, $useTouchscreen = TRUE)
{
    $errorStr = executeQueries($device, &$HCIPA, &$stepRows);

    if ($errorStr != "") {
        return $errorStr;
    }

    // Decide between "touchscreen" and "mouse" for CogTool
    if ($useTouchscreen === TRUE) {
        $deviceType = "touchscreen";
        $deviceAction =
<<<QUOTE
                        <touchscreenAction/>
QUOTE
        ;
    }
    else {
        $deviceType = "mouse";
        $deviceAction =
<<<QUOTE
                        <mouseAction/>
QUOTE
        ;
    }

    $action =
<<<QUOTE
                    <action>
{$deviceAction}
                    </action>
QUOTE
    ;

    startExport($HCIPA, $deviceType, $exportedXML);

    // Ready to export XML

    // Collect transitions for demonstration; currently, since
    // each frame contains one widget with one transition which
    // always uses the specified action (see above), we simply
    // need to map the source frame name to the destination frame name.
    $transitions = array();

    // Frame positioning; since we don't know the size, we'll assume
    // a default width and height and lay out the frames 5 in a row
    // left-to-right in odd numbered rows, right-to-left in even numbered rows.
    $frameWidth = 300;
    $frameHeight = 600;
    $framesPerRow = 5;
    $isOddRow = TRUE;

    $rowFrameIndex = 0;
    $frameX = 0;
    $frameY = 0;

    $startFrames = array();
    $lastStep = "";
    $endFrames = array();

    $frameName = "";
    $lastFrameName = "";
    $firstFrameName = "";

    // First think label
    $identifyLabel = "UNSET";
    $selectLabel = "UNSET";

    foreach ($stepRows as $i => $stepRow) {
        // Output frame with a dummy 25x25 widget in the top-left corner
        // (since we don't know where to place it, nor do we know its size)

        if (($stepRow["hcipa_step"] == HCIPA_IDENTIFY_TASK_STEP) &&
            ($stepRow["hcipa_order"] == 1))
        {
            $identifyLabel = $stepRow["next_user_action"];
            continue;
        }

        if (($stepRow["hcipa_step"] == HCIPA_SELECT_FUNCTION_STEP) &&
            ($stepRow["hcipa_order"] == 1))
        {
            $selectLabel = $stepRow["next_user_action"];
            continue;
        }

        if ($frameName != "") {
            $lastFrameName = $frameName;
        }

        $frameName = buildFrameName($stepRow);

        if ($i < count($stepRows) - 1) {
            $targetFrameName = buildFrameName($stepRows[$i + 1]);
        }
        else {
            $targetFrameName = FINAL_FRAME_NAME;
        }

        if ($firstFrameName == "") {
            $firstFrameName = $frameName;
        }

        if ($lastFrameName != "") {
            $transitions[$lastFrameName] = $frameName;
        }

        if ($stepRow["hcipa_order"] == 1) {
            $startFrames[$stepRow["hcipa_step"]] = $frameName;
        }

        if (($lastStep != "") && ($lastStep != $stepRow["hcipa_step"])) {
            $endFrames[$lastStep] = $frameName;
        }

        addFrame($stepRow, $frameName, $action, $frameX, $frameY,
                 $targetFrameName, $exportedXML);

        // Set up location of next frame
        if (++$rowFrameIndex < $framesPerRow) {
            if ($isOddRow) {
                $frameX += $frameWidth;
            }
            else {
                $frameX -= $frameWidth;
            }
        }
        else {
            // Time to move down a row
            $rowFrameIndex = 0;
            $frameY += $frameHeight;
        }

        if ($stepRow["hcipa_step"] > 2) {
            $lastStep = $stepRow["hcipa_step"];
        }
    }

    if ($lastStep != "") {
        $transitions[$frameName] = FINAL_FRAME_NAME;
        $endFrames[$lastStep] = FINAL_FRAME_NAME;
    }

    // Enter demonstrations and the transitions
    addPreTask($firstFrameName,
               $deviceType,
               "1) Identify Task: " . $HCIPA["identify_task"],
               $identifyLabel,
               $exportedXML);

    addPreTask($firstFrameName,
               $deviceType,
               "2) Select Function Step: " . $HCIPA["select_function"],
               $selectLabel,
               $exportedXML);

    $taskNameLabels =
        array(HCIPA_ACCESS_STEP  => "3) Access Step",
              HCIPA_ENTER_STEP   => "4) Enter Step",
              HCIPA_CONFIRM_STEP => "5) Confirm & Save Step",
              HCIPA_MONITOR_STEP => "6) Monitor Step");

    $expectedStepId = HCIPA_ACCESS_STEP;

    foreach ($startFrames as $stepId => $startFrame) {
        while ($expectedStepId < $stepId) {
            $taskName = $taskNameLabels[$expectedStepId];

            addDemonstration($taskName, $startFrame, $startFrame,
                             $transitions, $deviceType, $deviceAction,
                             $exportedXML);

            $expectedStepId++;
        }

        $taskName = $taskNameLabels[$stepId];
        $endFrame = $endFrames[$stepId];

        addDemonstration($taskName, $startFrame, $endFrame, $transitions,
                         $deviceType, $deviceAction, $exportedXML);

        $expectedStepId++;
    }

    while ($expectedStepId <= HCIPA_MONITOR_STEP) {
        $taskName = $taskNameLabels[$expectedStepId];

        addDemonstration($taskName, FINAL_FRAME_NAME, FINAL_FRAME_NAME,
                         $transitions, $deviceType, $deviceAction,
                         $exportedXML);

        $expectedStepId++;
    }

    terminateExport($exportedXML);

    return "";
}

function executeQueries($device, &$HCIPA, &$stepRows)
{
    if (TESTING) {
        return outputTestData(&$HCIPA, &$stepRows);
    }

    // I don't actually program against a MySQL db,
    // so I don't know if a semi-colon (;) is needed at the end of queries

    $query =
<<<QUERY
 SELECT description,
        identify_task,
        select_function,
        access,
        enter,
        confirm_save,
        monitor
   FROM HCIPA
  WHERE HCIPA_ID = {$device}
QUERY
    ;

    $result = mysql_query($query);

    if (! $result) {
        return "HCIPA query failed: " . mysql_error();
    }

    if (mysql_num_rows($result) == 0) {
        return "No HCIPA found for device: " . $device;
    }

    if (($HCIPA = mysql_fetch_assoc($result)) === FALSE) {
        return "HCIPA fetch failed for device: " . $device;
    }

    mysql_free_result($result);

    $query =
<<<QUERY
 SELECT hcipa_step,
        hcipa_order,
        image,
        image_name,
        image_size,
        image_type,
        next_user_action,
        label_user_action
   FROM HCIPA_Actions
  WHERE hcipa_id = {$device}
  ORDER BY hcipa_step, hcipa_order
QUERY
    ;

    $result = mysql_query($query);

    if (! $result) {
        return "HCIPA_Actions query failed: " . mysql_error();
    }

    if (mysql_num_rows($result) == 0) {
        return "No HCIPA_Actions found for device: " . $device;
    }

    $stepRows = array();

    while (($stepRow = mysql_fetch_assoc($result)) !== FALSE) {
        $stepRows[] = $stepRow;
    }

    mysql_free_result($result);

    return "";
}

function startExport($HCIPA, $deviceType, &$exportedXML)
{
    $deviceName = encodeXMLSafe($HCIPA["description"]);

    $exportedXML =
<<<QUOTE
<?xml version="1.0" encoding="UTF-8"?>
<cogtoolimport version="1">
    <design name="{$deviceName}">
        <device>{$deviceType}</device>

QUOTE
    ;
}

function addFrame($stepRow, $frameName, $action, $frameX, $frameY,
                  $targetFrameName, &$exportedXML)
{
    if (isset($stepRow["image"]) && ($stepRow["image"] != "")) {
        $imgData = str_replace(array('+', '/', '='),
                               array('.', '_', '-'),
			       base64_encode($stepRow["image"]));
        $imgName = encodeXMLSafe($stepRow["image_name"]);

        $imgXML =
<<<QUOTE
<backgroundImageData name="{$imgName}">{$imgData}</backgroundImageData>
            
QUOTE
        ;
    }
    else {
        $imgXML = "";
    }

    $targetFrame = encodeXMLSafe($targetFrameName);

    $transitionXML =
<<<QUOTE

                <transition destinationFrameName="{$targetFrame}">
{$action}
                </transition>
QUOTE
    ;

    $widgetLabel = encodeXMLSafe($stepRow["label_user_action"]);

    $exportedXML .=
<<<QUOTE
        <frame name="{$frameName}">
            {$imgXML}<topLeftOrigin x="{$frameX}" y="{$frameY}"/>
            <widget name="User_Action" type="button">
                <displayLabel>{$widgetLabel}</displayLabel>
                <extent x="0" y="0" width="25" height="25"/>{$transitionXML}
            </widget>
        </frame>

QUOTE
    ;
} // addFrame

function addPreTask($frameName, $deviceType, $taskName, $label, &$exportedXML)
{
    $frameName = encodeXMLSafe($frameName);
    $taskName = encodeXMLSafe($taskName);
    $label = encodeXMLSafe($label);

    $exportedXML .=
<<<QUOTE
        <demonstration taskName="{$taskName}" startFrameName="{$frameName}">
            <startingRightHandPosition>{$deviceType}</startingRightHandPosition>
            <demonstrationStep>
                <thinkStep durationInSecs="1.2" thinkLabel="{$label}"/>
            </demonstrationStep>
        </demonstration>

QUOTE
    ;
}

function buildMonitorStep()
{
    return '<lookAtWidgetStep lookAtWidgetName="User_Action"/>';
}

function addDemonstration($taskName, $startFrame, $endFrame, $transitions,
                          $deviceType, $deviceAction, &$exportedXML)
{
    $demonstrationStep =
<<<QUOTE
            <demonstrationStep>
                <actionStep targetWidgetName="User_Action">
{$deviceAction}
                </actionStep>
            </demonstrationStep>

QUOTE
    ;

    $taskName = encodeXMLSafe($taskName);
    $frameName = encodeXMLSafe($startFrame);

    $exportedXML .=
<<<QUOTE
        <demonstration taskName="{$taskName}" startFrameName="{$frameName}">
            <startingRightHandPosition>{$deviceType}</startingRightHandPosition>

QUOTE
    ;

    $currentFrame = $startFrame;

    do {
        $exportedXML .= $demonstrationStep;
        $currentFrame = $transitions[$currentFrame];
    } while (($currentFrame != $endFrame) &&
             isset($transitions[$currentFrame]));

    $exportedXML .= "        </demonstration>\n";
} // addDemonstration

function terminateExport(&$exportedXML)
{
    // Terminate the XML
    $exportedXML .=
<<<QUOTE
    </design>
</cogtoolimport>

QUOTE
    ;
}

function outputTestData(&$HCIPA, &$stepRows)
{
    $HCIPA =
        array("description" => "Garmin 530: Select Arrival airport tower frequency",
              "identify_task" => "Select Arrival airport tower frequency",
              "select_function" => "ARRIVAL AIRPORT FREQUENCY");

    $stepRows =
        array(array("hcipa_step" => 1,
                    "hcipa_order" => 1,
                    "image" => "",
                    "image_name" => "",
                    "image_size" => "",
                    "image_type" => "",
                    "next_user_action" => "Recognize need to: Select Arrival airport tower frequency",
                    "label_user_action" => "None"),
              array("hcipa_step" => 2,
                    "hcipa_order" => 1,
                    "image" => "",
                    "image_name" => "",
                    "image_size" => "",
                    "image_type" => "",
                    "next_user_action" => "Decide to use function: ARRIVAL AIRPORT FREQUENCY",
                    "label_user_action" => "None"),
              array("hcipa_step" => 3,
                    "hcipa_order" => 1,
                    "image" => "",
                    "image_name" => "",
                    "image_size" => "",
                    "image_type" => "",
                    "next_user_action" => "Rotate Small right knob",
                    "label_user_action" => "None"),
              array("hcipa_step" => 4,
                    "hcipa_order" => 1,
                    "image" => "",
                    "image_name" => "",
                    "image_size" => "",
                    "image_type" => "",
                    "next_user_action" => "Push right knob",
                    "label_user_action" => "CRSR"),
              array("hcipa_step" => 4,
                    "hcipa_order" => 2,
                    "image" => "",
                    "image_name" => "",
                    "image_size" => "",
                    "image_type" => "",
                    "next_user_action" => "Reach the Tower frequency cell",
                    "label_user_action" => "Large right knob"),
              array("hcipa_step" => 4,
                    "hcipa_order" => 3,
                    "image" => "",
                    "image_name" => "",
                    "image_size" => "",
                    "image_type" => "",
                    "next_user_action" => "Press Enter",
                    "label_user_action" => "ENT"),
              array("hcipa_step" => 5,
                    "hcipa_order" => 1,
                    "image" => "",
                    "image_name" => "",
                    "image_size" => "",
                    "image_type" => "",
                    "next_user_action" => "activate the tower frequency",
                    "label_user_action" => "COM flip-flop"),
              array("hcipa_step" => 5,
                    "hcipa_order" => 2,
                    "image" => "",
                    "image_name" => "",
                    "image_size" => "",
                    "image_type" => "",
                    "next_user_action" => "Look at Active Frequency",
                    "label_user_action" => "None"));
    return "";
} // outputTestData

if (TESTING) {
    exportHCIPAtoCogTool(46, $exportedXML);

    echo $exportedXML;
}

?>
