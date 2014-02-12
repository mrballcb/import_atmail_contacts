<?php
/**
 * Import atmail contacts
 *
 * Populates a new user's contacts with entries from @Mail.
 *
 * Users with contacts already in Roundcube are skipped.
 *
 * You must configure your Roundcube database credentials in db.inc.php:
 *
 *  $rcmail_config['atmail_dsn']  = 'pgsql:host=db.example.com;dbname=atmail';
 *  $rcmail_config['atmail_user'] = 'atmail';
 *  $rcmail_config['atmail_pass'] = 'password';
 *
 * You can configure two optional debugging settings in main.inc.php:
 *
 *  $rcmail_config['atmail_debug'] = true | false;
 *  $rcmail_config['atmail_debug_file'] = '/tmp/atmail_migration_debug.log';
 *
 * Tested against Atmail 4.x schema (the older perl version)
 * Not yet tested against Atmail 5.x schema
 * 
 * @version 1.2
 * @author Todd Lyons
 * Adapted from original Horde version by: @author Jason Meinzer
 * Original at: https://github.com/bithive/import_horde_identities
 *
 */
class import_atmail_contacts extends rcube_plugin
{
    public $task = 'login';
    private $log = 'import_atmail';

    function init()
    {
        $this->add_hook('login_after', array($this, 'fetch_atmail_objects'));
    }

    function fetch_atmail_objects()
    {
        $this->rc = rcmail::get_instance();
        $contacts = $this->rc->get_address_book(null, true);
        $this->load_config();

        if($contacts->count()->count > 0) return true; // exit early if user already has contacts

        $db_dsn  = $this->rc->config->get('atmail_dsn');
        $db_user = $this->rc->config->get('atmail_user');
        $db_pass = $this->rc->config->get('atmail_pass');

        try {
            $db = new PDO($db_dsn, $db_user, $db_pass);
        } catch(PDOException $e) {
            return false;
        }

        $uid = strtolower($this->rc->user->get_username());

        // First we migrate all contacts
        $table = "Abook_" .
                 (preg_match('/[a-z]/',$uid[0]) ? $uid[0] : 'other');
        $sth = $db->prepare('SELECT UserFirstName, UserLastName, UserMiddleName, '.
                            'UserEmail,UserEmail2,UserEmail3,UserEmail4,UserEmail5,'.
                            'UserHomeAddress, UserHomeCity, UserHomeState, '.
                            'UserHomeCountry, UserHomeZip, UserHomePhone, '.
                            'UserHomeMobile, UserHomeFax, '.
                            'UserWorkCompany, UserWorkDept, UserWorkTitle, '.
                            'UserWorkAddress, UserWorkCity, UserWorkState, '.
                            'UserWorkCountry, UserWorkZip, UserWorkPhone, '.
                            'UserWorkMobile, UserWorkFax, '.
                            'UserInfo, UserDOB '.
                            'FROM '.$table.' WHERE Account = :uid');
        
        $sth->bindParam(':uid', $uid);
        $sth->execute();

        $result = $sth->fetchAll(PDO::FETCH_ASSOC);

        $loop = 0;  // Number of contacts looked at
        $count = 0; // Number of contacts actually imported 
        $emails = array();
        foreach($result as $atmail) {
          if (strpos($atmail['UserEmail'], '@') !== false) {
            $to_add = strtolower(trim($atmail['UserEmail']));
            $record = array(
                'firstname'  => $atmail['UserFirstName'],
                'middlename' => $atmail['UserMiddleName'],
                'surname'    => $atmail['UserLastName'],
                'name'       => $atmail['UserFirstName'].' '.$atmail['UserLastName'],
                'email:work' => array( $to_add,
                                  $atmail['UserEmail2'], $atmail['UserEmail3'],
                                  $atmail['UserEmail4'], $atmail['UserEmail5']
                                ),
                'address:home'   => array( array(
                                      'street'   => $atmail['UserHomeAddress'],
                                      'locality' => $atmail['UserHomeCity'],
                                      'region'   => $atmail['UserHomeState'],
                                      'country'  => $atmail['UserHomeCountry'],
                                      'zipcode'  => $atmail['UserHomeZip']
                                    ) ),
                'phone:home'     => array($atmail['UserHomePhone']),
                'phone:mobile'   => array($atmail['UserHomeMobile']),
                'phone:homefax'  => array($atmail['UserHomeFax']),
                'organization'   => $atmail['UserWorkCompany'],
                'department'     => $atmail['UserWorkDept'],
                'jobtitle'       => $atmail['UserWorkTitle'],
                'address:work'   => array( array(
                                      'street'   => $atmail['UserWorkAddress'],
                                      'locality' => $atmail['UserWorkCity'],
                                      'region'   => $atmail['UserWorkState'],
                                      'country'  => $atmail['UserWorkCountry'],
                                      'zipcode'  => $atmail['UserWorkZip']
                                    ) ),
                'phone:work'     => array($atmail['UserWorkPhone']),
                'phone:work2'    => array($atmail['UserWorkMobile']),
                'phone:workfax'  => array($atmail['UserWorkFax']),
                'birthday'       => array($atmail['UserDOB']),
                'notes'          => array($atmail['UserInfo'])
            );

            if (check_email($record['email:work'][0])) {
                $return = $contacts->insert($record, true);
                if ($return) {
                    $emails[$to_add] = $return;
                    $count++;
                }
                $loop++;
            }
          }
        }

        // Second we migrate all the groups
        $table = "AbookGroup_" .
                 (preg_match('/[a-z]/',$uid[0]) ? $uid[0] : 'other');
        $sth = $db->prepare('SELECT GroupName, GroupEmail '.
                            'FROM '.$table.' WHERE Account = :uid');
        $sth->bindParam(':uid', $uid);
        $sth->execute();

        $result = $sth->fetchAll(PDO::FETCH_ASSOC);

        $gcount = 0;    // Number of groups created
        $gmcount = 0;   // Number of emails considered
        $goodgmcount = 0;  // Successfully added
        $duplicates = 0;   // Skipped duplicate emails
        $groups = array();
        $group_fail_email = array();
        foreach($result as $agroup) {
            $group_name = $agroup['GroupName'];
            if (array_key_exists($group_name, $groups) == false) {
                $return = $contacts->create_group($group_name);
                if ($return['id']) {
                    $groups[$group_name] = array('id' => $return['id'],
                                                 'emails' => array()  );
                    $gcount++;
                }
            }
            else {
                $to_add = strtolower(trim($agroup['GroupEmail']));
                if ($to_add != "" &&
                    $emails[$to_add] ) {
                  $gmcount++;
                  // Atmail will allow you to add duplicate addresses to a list.
                  // Detect it, count it, and skip adding this one.
                  if (array_search($to_add, $groups[$group_name]['emails'])) {
                    $duplicates++;
                  }
                  else {
                    // Add the contact, returnval is number of successful adds
                    $return = $contacts->add_to_group(
                                            $groups[$group_name]['id'],
                                            $emails[$to_add] );
                    $goodgmcount += $return;
                    if ($return == 0) {
                      // Failed, so store it for debugging
                      array_push($group_fail_email, $to_add);
                    }
                    else {
                      // Successfully added, store the email address
                      array_push($groups[$group_name]['emails'], $to_add);
                    }
                  }
                }
            }
        }

        // fallback to false
        $atmail_debug = rcube::get_instance()->config->get('atmail_debug', false); 
        if ($atmail_debug == true) {
          ob_start();
          print_r("$uid ".
                  "Groups created: $gcount, ".
                  "Emails considered: $gmcount, ".
                  "Successfully added to a group: $goodgmcount, ".
                  "Skipped duplicates: $duplicates\n");
          print_r(array("Imported Groups:",$groups));
          print_r(array("Imported Group failed emails:",$group_fail_email));
          $debug_output = ob_get_clean();
          $outfile = rcube::get_instance()->config->get('atmail_debug_file', '/tmp/rc_debug.log'); 
          file_put_contents($outfile, $debug_output, FILE_APPEND);
        }
        return true;
    }

}
?>
