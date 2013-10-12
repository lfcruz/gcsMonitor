<?php
/*
 * 
 * 
 */
// Defining Global Constants ---------------------------------------------------
define ("CONFIG_FILE","config.json");
define ("SEQUENCE_FILE","sequence.json");
define ("MSG_ERROR","ERROR");
define ("MSG_WARNING","WARNING");
define ("MSG_INFO","INFO");
define ("REF_CONFIG_LOAD","LoadConfig");
define ("REF_SEQUENCE_LOAD","LoadSequence");
define ("REF_CONNECTIONS","StablishConnection");
define ("REF_SENSOR","ConnectionsSensor");
define ("REF_STATUS","AppStatusSensor");
define ("REF_LASTID","LastIDHandler");
define ("REF_SQL","GettingDBRecord");
define ("REF_GENERAL","Main");
define ("REF_SENDINGDATA","SendData");
define ("SQL_STRING","QueryName");
define ("F_HEADER",0);
define ("F_ID",1);
define ("F_AMOUNT",2);
define ("F_BILLER",3);
define ("F_CONTRACT",4);
define ("F_CURRENCY",5);
define ("F_LOCALTXID",6);
define ("F_BILLERRSPCODE",7);
define ("F_PAYMENTDATE",8);
define ("F_REFCODE",9);
define ("F_STATUS",10);
define ("F_BILLERCOMMISSION",11);
define ("F_PAYMENTBROKERTXFEE",12);
define ("F_PAYMENTBROKERID",13);
define ("F_FOOTER",14);
define ("F_SEQUENCEID",15);

// Defining Global Variables ---------------------------------------------------
global $configStructure,
       $sequenceStructure,
       $dbConnector,
       $socketConnector,
       $vSequenceId,
       $vLoggingStatus,
       $vSystemStatus,
       $vTrxnString,
       $gotRecord,
       $vLastRecord;

//=============================================================================

function loadConfig() //Done.
{
// Defining Variables ---------------------------------------------------------
    global $configStructure,$sequenceStructure,$vSequenceId;
    $stringfile = "";
 
// Configuration file validation ----------------------------------------------
    writeLog(MSG_INFO, REF_CONFIG_LOAD,"Loading configuration file..........");   
    if(file_exists(CONFIG_FILE)){
        $stringfile = file_get_contents(CONFIG_FILE);
    }
    else {
        writeLog(MSG_ERROR, REF_CONFIG_LOAD, "Configuration file was not found (".CONFIG_FILE.").");
        exit;
    }
    $configStructure = json_decode($stringfile,true);
        /* Configuration file structure
         * monitorPrimaryHost
         * monitorPrimaryPort
         * monitorSecundaryHost
         * monitorSecundaryPort
         * bHubDbIp
         * bHubDbPort
         * bHubDbName
         * bHubDbUser
         * dHubDbPassword
         * dHubTrxnQuery
         */
    var_dump($configStructure);
    writeLog(MSG_INFO,REF_CONFIG_LOAD,"Configuration file loaded successfuly.....");
 
// Sequence file validation ---------------------------------------------------
    writeLog(MSG_INFO, REF_SEQUENCE_LOAD,"Loading sequence file..........");
    if(file_exists(SEQUENCE_FILE)){
        $stringfile = file_get_contents(SEQUENCE_FILE);
    }
    else {
        writeLog(MSG_ERROR, REF_SEQUENCE_LOAD, "Sequence file was not found (".SEQUENCE_FILE.").");
        exit;
    }
    $sequenceStructure = json_decode($stringfile,true);
        /* Configuration file structure
         * lastId
         */
    var_dump($sequenceStructure);
    $vSequenceId = $sequenceStructure["lastId"];
    writeLog(MSG_INFO,REF_SEQUENCE_LOAD,"Sequence file loaded successfuly.....");
 
// Ending function ------------------------------------------------------------
    return;
}

function writeLastId() //Done.
{
// Defining Variables ----------------------------------------------------------
    global $vSequenceId,$sequenceStructure,$gotRecord;
    $recrodSequence = "{\n\"lastId\":\"".$vSequenceId."\"\n}";

    if ($gotRecord){
        file_put_contents(SEQUENCE_FILE,$recrodSequence);
    }
    else{
        $stringfile = file_get_contents(SEQUENCE_FILE);
        $sequenceStructure = json_decode($stringfile,true);
        $vSequenceId = $sequenceStructure["lastId"];
        writeLog(MSG_WARNING,REF_LASTID,"Previous sequenceID ".$vSequenceId." was reloaded after an error sending data to Monitor socket.");
    }    
    return;
}

function stablishConnections() //Done.
{
// Defining Variables ----------------------------------------------------------
    global $configStructure,$dbConnector,$socketConnector;
    $connectorString = null;
    
// Connecting to Database ------------------------------------------------------
    $connectorString = "host=".$configStructure["bHubDbIp"].
                       " port=".$configStructure["bHubDbPort"].
                       " dbname=".$configStructure["bHubDbName"].
                       " user=".$configStructure["bHubDbUser"].
                       " password=".$configStructure["bHubDbPassword"];
    writeLog(MSG_INFO, REF_CONNECTIONS,"Connecting to database.......... ".$connectorString);
    $dbConnector = pg_connect($connectorString);
    if (!$dbConnector){
        writeLog(MSG_ERROR, REF_CONNECTIONS,"An error has occurred connecting to database ".$configStructure["bHubDbName"]);
        exit;
    }
    else {
        writeLog(MSG_INFO,REF_CONNECTIONS,"Database connection stablished successfuly.");
        pg_prepare($dbConnector,SQL_STRING,$configStructure["bHubTrxnQuery"]);
    }
    
// Connecting to Monitor -------------------------------------------------------
    writeLog(MSG_INFO, REF_CONNECTIONS,"Connecting to Monitor socket........ ");
    $socketConnector = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!socket_connect($socketConnector, $configStructure["monitorPrimaryHost"],$configStructure["monitorPrimaryPort"])){
        writeLog(MSG_ERROR, REF_CONNECTIONS,"Unable to create socket connection.");
        exit;
    }
    else {
        writeLog(MSG_INFO,REF_CONNECTIONS,"Monitor socket connection stablished successfuly.");
    }
 return true;
}

function connectionsSensor() //Done.
{
// Definig Variables -----------------------------------------------------------
    global $dbConnector,$socketConnector,$configStructure;
    
// Validating established database connection ----------------------------------
    if (pg_connection_status($dbConnector) === PGSQL_CONNECTION_BAD){
        writeLog(MSG_ERROR, REF_SENSOR,"Database link has been disconnected due to an unknown event.......");
        writeLog(MSG_WARNING, REF_SENSOR,"Starting recovery procedure........");
        $connectorString = "host=".$configStructure["bHubDbIp"].
                           " port=".$configStructure["bHubDbPort"].
                           " dbname=".$configStructure["bHubDbName"].
                           " user=".$configStructure["bHubDbUser"].
                           " password=".$configStructure["bHubDbPassword"];
        writeLog(MSG_WARNING, REF_SENSOR,"Connecting to database.......... ".$connectorString);
        $dbConnector = pg_connect($connectorString);
        if (!$dbConnector){
            writeLog(MSG_ERROR, REF_SENSOR,"An error has occurred connecting to database ".$configStructure["bHubDbName"]);
            echo "!!!!!!!!!! Please contact you DB-Administrator. !!!!!!!!!!\n";
            pg_clos($dbConnector);
            socket_close($socketConnector);
            exit;
        }
        else {
            writeLog(MSG_WARNING,REF_SENSOR,"Database connection restablished successfuly.");
            pg_prepare($dbConnector,SQL_STRING,$configStructure["bHubDbTrxnQuery"]);
        }

    }

// Validating established Monitor socket connection ----------------------------
    $sockread = socket_read($socketConnector,0);
    var_dump($sockread);
    if ($sockread === ''){
        writeLog(MSG_ERROR, REF_SENSOR,"Monitor socket link has been disconnected due to an unknown event.......");
        writeLog(MSG_WARNING, REF_SENSOR,"Starting recovery procedure........");
        writeLog(MSG_WARNING, REF_SENSOR,"Connecting to Monitor socket........ ");
        $socketConnector = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!socket_connect($socketConnector, $configStructure["monitorPrimaryHost"],$configStructure["monitorPrimaryPort"])){
            writeLog(MSG_ERROR, REF_SENSOR,"Unable to create socket connection.");
            echo "!!!!!!!!!! Please contact your Administrator. !!!!!!!!!!\n";
            pg_clos($dbConnector);
            socket_close($socketConnector);
            exit;
        }
        else {
            writeLog(MSG_WARNING,REF_SENSOR,"Monitor socket connection restablished successfuly.");
        }
        
    }
    return;
}

function appStatusMonitor() //Done.
{
// Defining Variables ----------------------------------------------------------
    global $configStructure,$vLoggingStatus,$vSystemStatus,$dbConnector,$socketConnector;
    
// Reload configuration file ---------------------------------------------------
    $stringfile = file_get_contents(CONFIG_FILE);
    $configStructure = json_decode($stringfile,true);
    
// System status evaluation ----------------------------------------------------
    switch ($configStructure["systemStatus"]) {
        case "Off":
            $vSystemStatus = false;
            writeLog(MSG_WARNING,REF_STATUS,"The system has been configured to shutdown!!");
            writeLog(MSG_INFO,REF_STATUS,"Shuting down..........");
            pg_close($dbConnector);
            writeLog(MSG_INFO,REF_STATUS,"Database disconnected..........");
            socket_close($socketConnector);
            writeLog(MSG_INFO,REF_STATUS,"Monitor socket disconnected..........");
            writeLog(MSG_INFO,REF_STATUS,"System is Off.");
            exit;
        case "Logging":
            if (!$vLoggingStatus){
                writeLog(MSG_INFO, REF_STATUS,"Logging started.......");
            }
            $vLoggingStatus = true;
            $vSystemStatus = true;
            break;
        case "On":
            if ($vLoggingStatus){
                writeLog(MSG_INFO, REF_STATUS,"Logging stoped.......");
            }
            $vSystemStatus = true;
            $vLoggingStatus = false;
            break;
        default:
            writeLog(MSG_WARNING,REF_STATUS,"Invalid system status detected, CHECK CONFIGURATION!!!!!!!!");
            $vSystemStatus = false;
            break;
    }
    return;
}

function writeLog($logType,$origReference,$logString) //Done.
{
   echo (date(DATE_RFC822)." ".$logType."[".$origReference."]: ".$logString)."\n";
   return;
}

function getLastTrxn() //Done.
{
// Defining Variables ----------------------------------------------------------
    global $dbConnector,$vSequenceId,$vLoggingStatus,$vTrxnString,$gotRecord,$vLastRecord;
    
// Execute query and extract next transaction record ---------------------------
    $gotRecord = false;
    $vLastRecord = false;
    $firstTime = false;
    
    //while ($lastRecord){
    $queryResult = pg_execute($dbConnector,SQL_STRING,array($vSequenceId));
    if (!$queryResult){
        writeLog(MSG_ERROR,REF_SQL,"Unable to retrieve next transaction record.");
    }
    else {
            $gotRecord = true;
            $recordString = pg_fetch_row($queryResult);
            $vTrxnString = $recordString[F_HEADER].
                   $recordString[F_ID].
                   $recordString[F_AMOUNT].
                   $recordString[F_BILLER].
                   $recordString[F_CONTRACT].
                   $recordString[F_CURRENCY].
                   $recordString[F_LOCALTXID].
                   $recordString[F_BILLERRSPCODE].
                   $recordString[F_PAYMENTDATE].
                   $recordString[F_REFCODE].
                   $recordString[F_STATUS].
                   $recordString[F_BILLERCOMMISSION].
                   $recordString[F_PAYMENTBROKERTXFEE].
                   $recordString[F_PAYMENTBROKERID].
                   $recordString[F_FOOTER];
            // Updating new sequence number ------------------------------------
            $newSequenceId = $recordString[F_SEQUENCEID];
            if ($newSequenceId != null){
                $vSequenceId = $newSequenceId;
                $firstTime = true;
                if($vLoggingStatus){
                    writeLog(MSG_INFO,REF_SQL,"Processing record id $vSequenceId.......... ");
                }
            }
            else{
                $vLastRecord = true;
                    if ($firstTime) {
                        $firstTime = false;
                    if ($vLoggingStatus){
                    writeLog(MSG_INFO,REF_LASTID,"Last record found, waiting for next transaction.......");
                    }
                }
            }
    }
    //}
    return;
}

function sendLastTrxn() //Done.
{
// Defining Variables ----------------------------------------------------------
    global $socketConnector,$vTrxnString,$vLoggingStatus,$gotRecord,$vLastRecord;
    
// Send record to Monitor socket -----------------------------------------------
    if (!$vLastRecord){
        $sendStatus = socket_write($socketConnector, $vTrxnString, strlen($vTrxnString));
        if (!$sendStatus){
            writeLog(MSG_ERROR,REF_SENDINGDATA,"Unable to write on Monitor socket........");
            $gotRecord = false;
        }
        elseif($vLoggingStatus){
            writeLog(MSG_INFO,REF_SQL,"Record sent: ".$vTrxnString);
        }
    }
    return;
}

function reConnectionsAttempts($reConnType)
{
// Definig Variables -----------------------------------------------------------
    global $dbConnector,$socketConnector,$configStructure;

    switch ($reConnType){
    // DB Reconnection Process -
    case "db":
            $reConnected = false;
            $delayer = 3;
            writeLog(MSG_ERROR, REF_SENSOR,"Database link has been disconnected due to an unknown event.......");
            writeLog(MSG_WARNING, REF_SENSOR,"Starting recovery procedure........");
            $connectorString = "host=".$configStructure["bHubDbIp"].
                               " port=".$configStructure["bHubDbPort"].
                               " dbname=".$configStructure["bHubDbName"].
                               " user=".$configStructure["bHubDbUser"].
                               " password=".$configStructure["bHubDbPassword"];
            echo("Connecting to database.");
            while (!$reConnected){
                $dbConnector = pg_connect($connectorString);
                if (!$dbConnector){
                    echo(".");
                
                
                    
                    writeLog(MSG_ERROR, REF_SENSOR,"An error has occurred connecting to database ".$configStructure["bHubDbName"]);
                    echo "!!!!!!!!!! Please contact you DB-Administrator. !!!!!!!!!!\n";
                    pg_clos($dbConnector);
                    socket_close($socketConnector);
                    exit;
                }
            else {
                writeLog(MSG_WARNING,REF_SENSOR,"Database connection restablished successfuly.");
                pg_prepare($dbConnector,SQL_STRING,$configStructure["bHubDbTrxnQuery"]);
            }

        }
    // Socket Reconnection Process -
    case "sk":   
    
    default:
        break;
    
    }

}
// Main function ---------------------------------------------------------------
loadConfig();
stablishConnections();
writeLog(MSG_INFO, REF_GENERAL,"System successfuly loaded!....");
while (true){
    appStatusMonitor();
    if ($vSystemStatus){
        connectionsSensor();
        getLastTrxn();
        if ($gotRecord){
            sendLastTrxn();
            writeLastId();
        }
    }
    sleep(5);
}
pg_close($dbConnector);
socket_close($socketConnector);
?>
