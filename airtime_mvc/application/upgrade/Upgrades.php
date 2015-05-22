<?php

class UpgradeManager
{
    /** Used to determine if the database schema needs an upgrade in order for this version of the Airtime codebase to work correctly.
     * @return array A list of schema versions that this version of the codebase supports.
     */
    public static function getSupportedSchemaVersions()
    {
        //What versions of the schema does the code support today:
        return array('2.5.12');
    }

    public static function checkIfUpgradeIsNeeded()
    {
        $schemaVersion = Application_Model_Preference::GetSchemaVersion();
        $supportedSchemaVersions = self::getSupportedSchemaVersions();
        $upgradeNeeded = !in_array($schemaVersion, $supportedSchemaVersions);
        if ($upgradeNeeded) {
            self::doUpgrade();
        }
    }

    public static function doUpgrade()
    {
        $upgradeManager = new UpgradeManager();
        $upgraders = array();
        array_push($upgraders, new AirtimeUpgrader253());
        array_push($upgraders, new AirtimeUpgrader254());
        array_push($upgraders, new AirtimeUpgrader255());
        array_push($upgraders, new AirtimeUpgrader259());
        array_push($upgraders, new AirtimeUpgrader2510());
        array_push($upgraders, new AirtimeUpgrader2511());
        array_push($upgraders, new AirtimeUpgrader2512());
        return $upgradeManager->runUpgrades($upgraders, (dirname(__DIR__) . "/controllers"));
    }

    /**
     * Run a given set of upgrades
     * 
     * @param array $upgraders the upgrades to perform
     * @param string $dir the directory containing the upgrade sql
     * @return boolean whether or not an upgrade was performed
     */
    public function runUpgrades($upgraders, $dir) {
        $upgradePerformed = false;
        
        for($i = 0; $i < count($upgraders); $i++) {
            $upgrader = $upgraders[$i];
            if ($upgrader->checkIfUpgradeSupported()) {
                // pass the given directory to the upgrades, since __DIR__ returns parent dir of file, not executor
                $upgrader->upgrade($dir); // This will throw an exception if the upgrade fails.
                $upgradePerformed = true;
                $i = 0; // Start over, in case the upgrade handlers are not in ascending order.
            }
        }
        
        return $upgradePerformed;
    }

}

abstract class AirtimeUpgrader
{
    /** Schema versions that this upgrader class can upgrade from (an array of version strings). */
    abstract protected function getSupportedSchemaVersions();
    /** The schema version that this upgrader class will upgrade to. (returns a version string) */
    abstract public function getNewVersion();

    public static function getCurrentSchemaVersion()
    {
        return Application_Model_Preference::GetSchemaVersion();
    }
    
    /** 
     * This function checks to see if this class can perform an upgrade of your version of Airtime
     * @return boolean True if we can upgrade your version of Airtime.
     */
    public function checkIfUpgradeSupported()
    {        
        if (!in_array(AirtimeUpgrader::getCurrentSchemaVersion(), $this->getSupportedSchemaVersions())) {
            return false;
        }
        return true;
    }
    
    protected function toggleMaintenanceScreen($toggle)
    {
        if ($toggle)
        {
            //Disable Airtime UI
            //create a temporary maintenance notification file
            //when this file is on the server, zend framework redirects all
            //requests to the maintenance page and sets a 503 response code
            /* DISABLED because this does not work correctly
            $this->maintenanceFile = isset($_SERVER['AIRTIME_BASE']) ? $_SERVER['AIRTIME_BASE']."maintenance.txt" : "/tmp/maintenance.txt";
            $file = fopen($this->maintenanceFile, 'w');
            fclose($file);
             */
        } else {
            //delete maintenance.txt to give users access back to Airtime
            /* DISABLED because this does not work correctly
            if ($this->maintenanceFile) {
                unlink($this->maintenanceFile);
            }*/
        }
    }
            
    /** Implement this for each new version of Airtime */
    abstract public function upgrade();
}

class AirtimeUpgrader253 extends AirtimeUpgrader
{
    protected function getSupportedSchemaVersions()
    {
        return array('2.5.1', '2.5.2');

    }
    public function getNewVersion()
    {
        return '2.5.3';
    }
    
    public function upgrade($dir = __DIR__)
    {
        Cache::clear();
        assert($this->checkIfUpgradeSupported());
        
        $con = Propel::getConnection();
        $con->beginTransaction();
        try {
            
            $this->toggleMaintenanceScreen(true);
            Cache::clear();
            
            //Begin upgrade
        
            //Update disk_usage value in cc_pref
            $musicDir = CcMusicDirsQuery::create()
            ->filterByType('stor')
            ->filterByExists(true)
            ->findOne();
            $storPath = $musicDir->getDirectory();
        
            //Update disk_usage value in cc_pref
            $storDir = isset($_SERVER['AIRTIME_BASE']) ? $_SERVER['AIRTIME_BASE']."srv/airtime/stor" : "/srv/airtime/stor";
            $diskUsage = shell_exec("du -sb $storDir | awk '{print $1}'");
        
            Application_Model_Preference::setDiskUsage($diskUsage);
                    
            //clear out the cache
            Cache::clear();
            
            $con->commit();
        
            //update system_version in cc_pref and change some columns in cc_files
            $airtimeConf = isset($_SERVER['AIRTIME_CONF']) ? $_SERVER['AIRTIME_CONF'] : "/etc/airtime/airtime.conf";
            $values = parse_ini_file($airtimeConf, true);
        
            $username = $values['database']['dbuser'];
            $password = $values['database']['dbpass'];
            $host = $values['database']['host'];
            $database = $values['database']['dbname'];
        
            passthru("export PGPASSWORD=$password && psql -h $host -U $username -q -f $dir/upgrade_sql/airtime_".$this->getNewVersion()."/upgrade.sql $database 2>&1 | grep -v -E \"will create implicit sequence|will create implicit index\"");
            Application_Model_Preference::SetSchemaVersion($this->getNewVersion());

            //clear out the cache
            Cache::clear();
            
            $this->toggleMaintenanceScreen(false);
                    
        } catch (Exception $e) {
            $con->rollback();
            $this->toggleMaintenanceScreen(false);
        }        
    }
}

class AirtimeUpgrader254 extends AirtimeUpgrader
{
    protected function getSupportedSchemaVersions()
    {
        return array('2.5.3');
    }
    public function getNewVersion()
    {
        return '2.5.4';
    }
    
    public function upgrade()
    {
        Cache::clear();
        
        assert($this->checkIfUpgradeSupported());
        
        $newVersion = $this->getNewVersion();
        
        $con = Propel::getConnection();
        //$con->beginTransaction();
        try {
            $this->toggleMaintenanceScreen(true);
            Cache::clear();
            
            //Begin upgrade

            //First, ensure there are no superadmins already.
            $numberOfSuperAdmins = CcSubjsQuery::create()
            ->filterByDbType(UTYPE_SUPERADMIN)
            ->filterByDbLogin("sourcefabric_admin", Criteria::NOT_EQUAL) //Ignore sourcefabric_admin users
            ->count();
            
            //Only create a super admin if there isn't one already.
            if ($numberOfSuperAdmins == 0)
            {
                //Find the "admin" user and promote them to superadmin.
                $adminUser = CcSubjsQuery::create()
                ->filterByDbLogin('admin')
                ->findOne();
                if (!$adminUser)
                {
                    //TODO: Otherwise get the user with the lowest ID that is of type administrator:
                    //
                    $adminUser = CcSubjsQuery::create()
                    ->filterByDbType(UTYPE_ADMIN)
                    ->orderByDbId(Criteria::ASC)
                    ->findOne();
                    
                    if (!$adminUser) {
                        throw new Exception("Failed to find any users of type 'admin' ('A').");
                    }
                }
                
                $adminUser = new Application_Model_User($adminUser->getDbId());
                $adminUser->setType(UTYPE_SUPERADMIN);
                $adminUser->save();
                Logging::info($_SERVER['HTTP_HOST'] . ': ' . $newVersion . " Upgrade: Promoted user " . $adminUser->getLogin() . " to be a Super Admin.");
                
                //Also try to promote the sourcefabric_admin user
                $sofabAdminUser = CcSubjsQuery::create()
                ->filterByDbLogin('sourcefabric_admin')
                ->findOne();
                if ($sofabAdminUser) {
                    $sofabAdminUser = new Application_Model_User($sofabAdminUser->getDbId());
                    $sofabAdminUser->setType(UTYPE_SUPERADMIN);
                    $sofabAdminUser->save();
                    Logging::info($_SERVER['HTTP_HOST'] . ': ' . $newVersion . " Upgrade: Promoted user " . $sofabAdminUser->getLogin() . " to be a Super Admin.");                  
                }
            }
            
            //$con->commit();
            Application_Model_Preference::SetSchemaVersion($newVersion);
            Cache::clear();
            
            $this->toggleMaintenanceScreen(false);
                        
            return true;
            
        } catch(Exception $e) {
            //$con->rollback();
            $this->toggleMaintenanceScreen(false);
            throw $e; 
        }
    }
}

class AirtimeUpgrader255 extends AirtimeUpgrader {
    protected function getSupportedSchemaVersions() {
        return array (
            '2.5.4'
        );
    }

    public function getNewVersion() {
        return '2.5.5';
    }

    public function upgrade($dir = __DIR__) {
        Cache::clear();
        assert($this->checkIfUpgradeSupported());

        $newVersion = $this->getNewVersion();

        try {
            $this->toggleMaintenanceScreen(true);
            Cache::clear();
            
            // Begin upgrade
            $airtimeConf = isset($_SERVER['AIRTIME_CONF']) ? $_SERVER['AIRTIME_CONF'] : "/etc/airtime/airtime.conf";
            $values = parse_ini_file($airtimeConf, true);
            
            $username = $values['database']['dbuser'];
            $password = $values['database']['dbpass'];
            $host = $values['database']['host'];
            $database = $values['database']['dbname'];

            passthru("export PGPASSWORD=$password && psql -h $host -U $username -q -f $dir/upgrade_sql/airtime_"
                    .$this->getNewVersion()."/upgrade.sql $database 2>&1 | grep -v -E \"will create implicit sequence|will create implicit index\"");
            
            Application_Model_Preference::SetSchemaVersion($newVersion);
            Cache::clear();
            
            $this->toggleMaintenanceScreen(false);
            
            return true;
        } catch(Exception $e) {
            $this->toggleMaintenanceScreen(false);
            throw $e;
        }
    }
}

class AirtimeUpgrader259 extends AirtimeUpgrader {
    protected function getSupportedSchemaVersions() {
        return array (
            '2.5.5'
        );
    }
    
    public function getNewVersion() {
        return '2.5.9';
    }
    
    public function upgrade($dir = __DIR__) {
        Cache::clear();
        assert($this->checkIfUpgradeSupported());
        
        $newVersion = $this->getNewVersion();
        
        try {
            $this->toggleMaintenanceScreen(true);
            Cache::clear();
            
            // Begin upgrade
            $airtimeConf = isset($_SERVER['AIRTIME_CONF']) ? $_SERVER['AIRTIME_CONF'] : "/etc/airtime/airtime.conf";
            $values = parse_ini_file($airtimeConf, true);
            
            $username = $values['database']['dbuser'];
            $password = $values['database']['dbpass'];
            $host = $values['database']['host'];
            $database = $values['database']['dbname'];
                
            passthru("export PGPASSWORD=$password && psql -h $host -U $username -q -f $dir/upgrade_sql/airtime_"
                     .$this->getNewVersion()."/upgrade.sql $database 2>&1 | grep -v -E \"will create implicit sequence|will create implicit index\"");
            
            Application_Model_Preference::SetSchemaVersion($newVersion);
            Cache::clear();
            
            $this->toggleMaintenanceScreen(false);
        } catch(Exception $e) {
            $this->toggleMaintenanceScreen(false);
            throw $e;
        }
    }
}

class AirtimeUpgrader2510 extends AirtimeUpgrader
{
    protected function getSupportedSchemaVersions() {
        return array (
            '2.5.9'
        );
    }

    public function getNewVersion() {
        return '2.5.10';
    }

    public function upgrade($dir = __DIR__) {
        Cache::clear();
        assert($this->checkIfUpgradeSupported());

        $newVersion = $this->getNewVersion();

        try {
            $this->toggleMaintenanceScreen(true);
            Cache::clear();

            // Begin upgrade
            $airtimeConf = isset($_SERVER['AIRTIME_CONF']) ? $_SERVER['AIRTIME_CONF'] : "/etc/airtime/airtime.conf";
            $values = parse_ini_file($airtimeConf, true);

            $username = $values['database']['dbuser'];
            $password = $values['database']['dbpass'];
            $host = $values['database']['host'];
            $database = $values['database']['dbname'];

            passthru("export PGPASSWORD=$password && psql -h $host -U $username -q -f $dir/upgrade_sql/airtime_"
                .$this->getNewVersion()."/upgrade.sql $database 2>&1 | grep -v -E \"will create implicit sequence|will create implicit index\"");

            Application_Model_Preference::SetSchemaVersion($newVersion);
            Cache::clear();

            $this->toggleMaintenanceScreen(false);
        } catch(Exception $e) {
            $this->toggleMaintenanceScreen(false);
            throw $e;
        }
    }
}

class AirtimeUpgrader2511 extends AirtimeUpgrader
{
    protected function getSupportedSchemaVersions() {
        return array (
            '2.5.10'
        );
    }

    public function getNewVersion() {
        return '2.5.11';
    }

    public function upgrade($dir = __DIR__) {
        Cache::clear();
        assert($this->checkIfUpgradeSupported());

        $newVersion = $this->getNewVersion();

        try {
            $this->toggleMaintenanceScreen(true);
            Cache::clear();

            // Begin upgrade
            $queryResult = CcFilesQuery::create()
                ->select(array('disk_usage'))
                ->withColumn('SUM(CcFiles.filesize)', 'disk_usage')
                ->find();
            $disk_usage = $queryResult[0];
            Application_Model_Preference::setDiskUsage($disk_usage);

            Application_Model_Preference::SetSchemaVersion($newVersion);
            Cache::clear();

            $this->toggleMaintenanceScreen(false);
        } catch(Exception $e) {
            $this->toggleMaintenanceScreen(false);
            throw $e;
        }
    }
    public function downgrade() {

    }
}

class AirtimeUpgrader2512 extends AirtimeUpgrader
{
    protected function getSupportedSchemaVersions() {
        return array (
            '2.5.10',
            '2.5.11'
        );
    }

    public function getNewVersion() {
        return '2.5.12';
    }

    public function upgrade($dir = __DIR__) {
        Cache::clear();
        assert($this->checkIfUpgradeSupported());

        $newVersion = $this->getNewVersion();

        try {
            $this->toggleMaintenanceScreen(true);
            Cache::clear();

            // Begin upgrade
            $airtimeConf = isset($_SERVER['AIRTIME_CONF']) ? $_SERVER['AIRTIME_CONF'] : "/etc/airtime/airtime.conf";
            $values = parse_ini_file($airtimeConf, true);

            $username = $values['database']['dbuser'];
            $password = $values['database']['dbpass'];
            $host = $values['database']['host'];
            $database = $values['database']['dbname'];

            passthru("export PGPASSWORD=$password && psql -h $host -U $username -q -f $dir/upgrade_sql/airtime_"
                .$this->getNewVersion()."/upgrade.sql $database 2>&1 | grep -v -E \"will create implicit sequence|will create implicit index\"");

            Application_Model_Preference::SetSchemaVersion($newVersion);
            Cache::clear();

            $this->toggleMaintenanceScreen(false);
        } catch(Exception $e) {
            $this->toggleMaintenanceScreen(false);
            throw $e;
        }
    }
    public function downgrade() {

    }
}
