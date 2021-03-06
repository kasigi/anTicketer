<?php


class anTicUser {

    public $db;
    public $validRequests = array("login","logout","setpassword","whoami","listgroups","listusers");

    function anTicUser(){

        // Create the Session
        session_start();
        session_regenerate_id();


    }
/*
 * Initializes the database connection for the user class if not already connected
 */
    function initDB()
    {
        global $dbAuth;

        if ($this->db instanceof PDO) {
            $status = $this->db->getAttribute(PDO::ATTR_CONNECTION_STATUS);
        } else {

            $settingsSet = require(dirname(__FILE__).'/../../systemSettings.php');

            // Check System Settings
            if (!$settingsSet) {
                // The system settings and DB connection values not set. Return Failure.
                $returnData['error'] = "System Settings File Missing.";
                return $returnData;
            }


            // Create Database Connection
            $this->db = new PDO("mysql:host=" . $dbAuth['addr'] . ";port=" . $dbAuth['port'] . ";dbname=" . $dbAuth['schema'] . ";charset=utf8mb4", $dbAuth['user'], $dbAuth['pw']);

        }
        return $this->db;
    } // end initDB

/*
 * Gathers the inputs submitted via $_REQUEST, etc. and prepares theme for an action. The primary purpose is to handle angularJS's approach to POST.
 *
 */
    function gatherInputs()
    {

        // Gather data from angular's post method
        $postdata = file_get_contents("php://input");
        $aRequest = json_decode($postdata, true);


// Check for valid request action
        if (!isset($aRequest['action'])) {
            $returnData['error'] = "No action defined";
            return $returnData;
        }

// Check for valid request action
        $aRequest['action'] = strtolower($aRequest['action']);
        if (!in_array($aRequest['action'], $this->validRequests)) {
            $returnData['error'] = "Invalid Request Type";
            return $returnData;
        }




        $returnArr = [];
        $returnArr['action'] = $aRequest['action'];
        $returnArr['email'] = $aRequest['email'];
        $returnArr['password'] = $aRequest['password'];
        $returnArr['groupIDs'] = $aRequest['groupIDs'];
        $returnArr['userIDs'] = $aRequest['userIDs'];

        return $returnArr;

    }// end function gatherInputs


    function checkLogin(){
        // Checks for the presence of a logged in user

        if((!isset($_SESSION['userID']) || $_SESSION['userID']<=0)){
            return false;
        }else{
            return true;
        }

    } // checkLogin


    function whoami(){

        if($this->checkLogin()){
            $this->initDB();
            $sql = "SELECT userID, email, firstName, lastName FROM anticUser WHERE userID=:userID LIMIT 0,1";
            $statement = $this->db->prepare($sql);
            $statement->bindValue(":userID",intval($_SESSION['userID']));
            $statement->execute();

            // Compare the hashes
            $data = null;
            while ($dbdata = $statement->fetch(PDO::FETCH_ASSOC)) {
                $data = $dbdata;
            }

            $output['data']=$data;
        }else{
            $output['status'] = "Not logged in.";
        }
            return $output;
    }// whoami


    function logout(){
        $_SESSION['fullyAuthenticated'] = false;
        unset($_SESSION['userID']);
        unset($_SESSION['userMeta']);
    }//logout

/*
 * Authenticates a user with email address and password.
 * @param $userEmail string The email address of the user
 * @param $password string  The password to verify
 */
    function login($userEmail,$password){
        if($userEmail == "" || $password == ""){
            $this->returnError("Not logged in.",2);
            return false;
        }

        $this->initDB();

        // Get the prospective user record
        $sql = "SELECT * FROM anticUser WHERE email = :email AND allowLogin = 1 and active = 1";
        $statement = $this->db->prepare($sql);
        $statement->bindValue(":email",$userEmail);
        $statement->execute();

        // Compare the hashes
        $data = null;
        while ($dbdata = $statement->fetch(PDO::FETCH_ASSOC)) {
            $data = $dbdata;
        }

        if (isset($data['password']) && password_verify($password, $data['password'])) {
            // If successful, store userID and First/Last name in session and set fullyAuthenticated to true

            $output['status'] = "success";
            $_SESSION['userID'] = intval($data['userID']);
            $_SESSION['fullyAuthenticated'] = true;
            $_SESSION['userMeta'] = [];
            $_SESSION['userMeta']['firstName'] = $data['firstName'];
            $_SESSION['userMeta']['lastName'] = $data['lastName'];
            $this->returnError("Login Success",1);

        } else {
            // If they fail, unset fullyAuthenticated and session user id return false
            $_SESSION['fullyAuthenticated'] = false;
            unset($_SESSION['userID']);
            unset($_SESSION['userMeta']);
            $this->returnError("Login failed.",2);

        }


        return $output;

    }// end login



/*
 * Sets the password for a user
 * This function requires that the user being modified is either the user logged on OR that the user has admin powers on the users table.
 *
 * @param $userID int   The userID being modified.  If set to null or 0, it will default to the session userID
 * @param $password string  This is the password value to be hashed and saved.
 */
    function setPassword($userID,$password){

        // Set default value for userID if not defined
        if(!isset($userID) || $userID == null || $userID == ""){
            $userID = $_SESSION['userID'];
        }else{
            $userID = intval($userID);

            if($userID <=0){
                return $this->returnError("Not logged in.",2);
            }
        }
        if(!isset($password) || $password == ""){
            return false;
        }

        if($userID != $_SESSION['userID']){
            // Changing password for another user. Verify permission.
            $permissions = $this->permissionCheck("anticUser");
            if(!isset($permissions['data']['anticWrite']) || $permissions['data']['anticWrite']!=1) {
                // User does not have admin power over user table
                return $this->returnError("Inadequate permissions or record does not exist",2);

            }
        }
        // Connect DB
        $this->initDB();

        // Hash PW
        $options = ['cost' => 12];
        $hashedPW = password_hash($password, PASSWORD_BCRYPT, $options);

        // Prepare Update
        $sql = "UPDATE anticUser SET password=:password WHERE userID=:userID";

        $statement = $this->db->prepare($sql);

        $statement->bindValue(':password', $hashedPW);
        $statement->bindValue(':userID', intval($userID));

        // Run Update and Return Result
        $success = $statement->execute();
        if (!$success) {
            $output['status'] = "error";
            $output['error'] = $statement->errorCode();
            $output['sqlError'] = $statement->errorInfo();
            //$output['sqlError']['sql']=$sql;
        }else{
            $output['status'] = "success";
        }

        return $output;

    }// setPassword


    /*
     * Checks permissions for a single element or table.
     *
     * @param string $tableName This is the name of the table requested.
     * @param array/string $primaryKeys This can be an array of all required primary key/value pairs OR a JSON object of the same
     * @param int   $userID This optionally is the userID who's permissions are being checked. If not set, it will default to the session userID.
     *
     */
    function permissionCheck($tableName,$primaryKeys,$userID){
        if(!isset($userID)){
            $userID = $_SESSION['userID'];
        }else{
            $userID = intval($userID);
            if($userID <=0){
                return $this->returnError("Not logged in.",2);
            }
        }
        $this->initDB();

        $sql = "SELECT IF(sum(PMU.read)>=1,1,0) as `anticRead`, IF(sum(PMU.write)>=1,1,0) as `anticWrite`,IF(sum(PMU.execute)>=1,1,0) as `anticExecute`,IF(sum(PMU.administer)>=1,1,0) as `anticAdminister` FROM
(SELECT P.* FROM anticPermission P
INNER JOIN anticUserGroup UP ON UP.groupID = P.groupID AND UP.userID = :userID
WHERE P.tableName = :tableName
AND (P.pkArrayBaseJSON IS NULL OR P.pkArrayBaseJSON = '' OR P.pkArrayBaseJSON = :pkJSON)
UNION 
SELECT P.* FROM anticPermission P
WHERE P.tableName = :tableName
AND (P.pkArrayBaseJSON IS NULL OR P.pkArrayBaseJSON = '' OR P.pkArrayBaseJSON = :pkJSON)
AND P.groupID IS NULL
AND P.userID = :userID) as PMU;";


        $statement = $this->db->prepare($sql);

        $statement->bindValue(':userID', $userID);
        $statement->bindValue(':tableName', $tableName);
        if(is_array($primaryKeys)){
            $primaryKeys = json_encode($primaryKeys);
        }
        $statement->bindValue(':pkJSON', $primaryKeys);

        $success = $statement->execute();
        if (!$success) {
            $output['status'] = "error";
            $output['error'] = $statement->errorCode();
            $output['sqlError'] = $statement->errorInfo();
            //$output['sqlError']['sql']=$sql;
        }else{
            while ($data = $statement->fetch(PDO::FETCH_ASSOC)) {
                $output['status'] = "success";
                foreach($data as $key=>$value){
                    $data[$key]=intval($value);
                }
                $output['data'] = $data;
                //$output['sql']=$sql;
            }
        }

        return $output;
    } // permissionCheck

    function listGroups($groupIDs){
        if(!isset($userID)){
            $userID = $_SESSION['userID'];
        }else{
            $userID = intval($userID);
            if($userID <=0){
                return $this->returnError("Not logged in.",2);
            }
        }

        $groupWhere = "";
        if($groupIDs != ""){
            if(is_array($groupIDs)){
                foreach($groupIDs as $key=>$value){
                    $groupIDs[$key] = intval($value);
                }
                $groupIDs = implode(",",$groupIDs);
            }else{
                $groupIDs  = preg_replace("/[^0-9,]/", "", $groupIDs);
            }

            $groupWhere = "WHERE groupID in ($groupIDs)";
        }

        $sql = "SELECT * FROM anticGroup $groupWhere";

        $this->initDB();
        $statement = $this->db->prepare($sql);
        $success = $statement->execute();

        if (!$success) {
            $output['status'] = "error";
            $output['error'] = $statement->errorCode();
            $output['sqlError'] = $statement->errorInfo();
        }else{
            while ($data = $statement->fetchAll(PDO::FETCH_ASSOC)) {
                $output['status'] = "success";
                $output['data'] = $data;
            }
        }
        return $output;
    }//listGroups



    function listUsers($userIDs,$groupIDs){
        if(!isset($userID)){
            $userID = $_SESSION['userID'];
        }else{
            $userID = intval($userID);
            if($userID <=0){
                return $this->returnError("Not logged in.",2);
            }
        }

        $where = [];
        if($userIDs != ""){
            // Scan input for acceptable values
            if(is_array($userIDs)){
                foreach($userIDs as $key=>$value){
                    $userIDs[$key] = intval($value);
                }
                $userIDs = implode(",",$userIDs);
            }


                $userIDs  = preg_replace("/[^0-9,]/", "", $userIDs);

            // If there is still valid input after the filtering
            if($userIDs!=""){
                $where[] = " U.userID in ($userIDs)";
            }
        }

        if($groupIDs != ""){
            // Scan input for acceptable values
            if(is_array($groupIDs)){
                foreach($groupIDs as $key=>$value){
                    $groupIDs[$key] = intval($value);
                }
                $groupIDs = implode(",",$groupIDs);
            }

            $groupIDs  = preg_replace("/[^0-9,]/", "", $groupIDs);


            // If there is still valid input after the filtering
            if($groupIDs!=""){
                $groupJoin = " LEFT JOIN anticUserGroup G on U.userID = G.userID ";
                $where[] = " G.groupID in ($groupIDs)";
            }
        }else{
            $groupJoin = null;
        }


        // Build the SQL
        $sql = "SELECT * FROM anticUser U $groupJoin";
        if(count($where)>0){
            $sql .= " WHERE ". implode(" AND ",$where);
        }

        $this->initDB();
        $statement = $this->db->prepare($sql);
        $success = $statement->execute();

        if (!$success) {
            $output['status'] = "error";
            $output['error'] = $statement->errorCode();
            $output['sqlError'] = $statement->errorInfo();
        }else{
            while ($data = $statement->fetchAll(PDO::FETCH_ASSOC)) {
                $output['status'] = "success";

                $output['data'] = $data;
            }
        }
        return $output;

    }//listGroups




    function returnError($message,$errorType) {


        $this->initDB();
        if(!isset($errorType)){
            $errorType = 2;
        }

        if(!isset($_SESSION['userID'])){
            $userID = 0;
        }else{
            $userID = $_SESSION['userID'];
        }

        $sql = "INSERT INTO anticSystemLog (eventTypeID,eventDesc,userID,sourceIP) VALUES (:eventTypeID,:eventDesc,:userID,:sourceIP)";
        $statement = $this->db->prepare($sql);
        $statement->bindValue(":eventTypeID", $errorType);
        $statement->bindValue(":eventDesc", $message);
        $statement->bindValue(":userID", $userID );
        $statement->bindValue(":sourceIP", $_SERVER['REMOTE_ADDR']);

        $statement->execute();
        while ($data = $statement->fetch(PDO::FETCH_ASSOC)) {
            $output['status'] = "success";
            $output['data'][] = $data;
            //$output['sql']=$sql;
        }

        $output['status']="error";
        $output['errorType']=$errorType;
        return $output;

    }// end returnError




} // end class anTicUser