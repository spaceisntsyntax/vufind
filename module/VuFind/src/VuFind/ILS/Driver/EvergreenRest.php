<?php
/**
 * Evergreen REST API driver
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2016-2020.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  ILS_Drivers
 * @author   Mike Rylander <mrylander@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:ils_drivers Wiki
 */
namespace VuFind\ILS\Driver;

use Laminas\Log\LoggerAwareInterface;
use VuFind\Date\DateException;
use VuFind\Exception\ILS as ILSException;
use VuFind\I18n\Translator\TranslatorAwareInterface;
use VuFindHttp\HttpServiceAwareInterface;

class EvergreenRest extends AbstractBase implements TranslatorAwareInterface,
    HttpServiceAwareInterface, LoggerAwareInterface
{
    use \VuFind\Cache\CacheTrait;
    use \VuFind\Log\LoggerAwareTrait {
        logError as error;
    }
    use \VuFindHttp\HttpServiceAwareTrait;
    use \VuFind\I18n\Translator\TranslatorAwareTrait;

    /**
     * Driver configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Date converter
     *
     * @var \VuFind\Date\Converter
     */
    protected $dateConverter;

    /**
     * Factory function for constructing the SessionContainer.
     *
     * @var callable
     */
    protected $sessionFactory;

    /**
     * Session cache
     *
     * @var \Laminas\Session\Container
     */
    protected $sessionCache;

    /**
     * Whether item holds are enabled
     *
     * @var bool
     */
    protected $itemHoldsEnabled;

    /**
     * Item circ modifiers for which item level hold is restricted by VF
     *
     * @var array
     */
    protected $itemHoldExcludedCircMods;

    /**
     * Bib levels for which title level hold is allowed
     *
     * @var array
     */
    protected $titleHoldBibLevels;

    /**
     * Default pickup location
     *
     * @var string
     */
    protected $defaultPickUpLocation;

    /**
     * Whether to check that items exist when placing a hold
     *
     * @var bool
     */
    protected $checkItemsExist;

    /**
     * Item statuses that allow placing a hold
     *
     * @var array
     */
    protected $validHoldStatuses;

    /**
     * Mappings from item status codes to VuFind strings
     *
     * @var array
     */
    protected $itemStatusMappings = [
         '0' => 'Available',
         '1' => 'Checked out',
         '2' => 'Bindery',
         '3' => 'Lost',
         '4' => 'Missing',
         '5' => 'In process',
         '6' => 'In transit',
         '7' => 'Reshelving',
         '8' => 'On holds shelf',
         '9' => 'On order',
        '10' => 'ILL',
        '11' => 'Cataloging',
        '12' => 'Reserves',
        '13' => 'Discard/Weed',
        '14' => 'Damaged',
        '15' => 'On reservation shelf',
        '16' => 'Long Overdue',
        '17' => 'Lost and Paid',
        '18' => 'Canceled Transit'
    ];

    /**
     * Whether to sort items by enumchron. Default is true.
     *
     * @var array
     */
    protected $sortItemsByEnumChron;

    /**
     * Constructor
     *
     * @param \VuFind\Date\Converter $dateConverter  Date converter object
     * @param callable               $sessionFactory Factory function returning
     * SessionContainer object
     */
    public function __construct(
        \VuFind\Date\Converter $dateConverter,
        $sessionFactory
    ) {
        $this->dateConverter = $dateConverter;
        $this->sessionFactory = $sessionFactory;
    }

    /**
     * Set configuration.
     *
     * Set the configuration for the driver.
     *
     * @param array $config Configuration array (usually loaded from a VuFind .ini
     * file whose name corresponds with the driver class name).
     *
     * @return void
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * Initialize the driver.
     *
     * Validate configuration and perform all resource-intensive tasks needed to
     * make the driver active.
     *
     * @throws ILSException
     * @return void
     */
    public function init()
    {
        // Validate config
        $required = ['host', 'proxy_user', 'proxy_password'];
        foreach ($required as $current) {
            if (!isset($this->config['Catalog'][$current])) {
                throw new ILSException("Missing Catalog/{$current} config setting.");
            }
        }

        $this->validHoldStatuses
            = !empty($this->config['Holds']['valid_hold_statuses'])
            ? explode(':', $this->config['Holds']['valid_hold_statuses'])
            : [];

        $this->itemHoldsEnabled
            = $this->config['Holds']['enableItemHolds'] ?? false;

        $this->itemHoldExcludedCircMods
            = !empty($this->config['Holds']['item_hold_excluded_circ_mods'])
            ? explode(':', $this->config['Holds']['item_hold_excluded_circ_mods'])
            : [];

        $this->defaultPickUpLocation
            = $this->config['Holds']['defaultPickUpLocation'] ?? '';
        if ($this->defaultPickUpLocation === 'user-selected') {
            $this->defaultPickUpLocation = false;
        }

        if (!empty($this->config['ItemStatusMappings'])) {
            $this->itemStatusMappings = array_merge(
                $this->itemStatusMappings,
                $this->config['ItemStatusMappings']
            );
        }

        // Init session cache for session-specific data
        $namespace = md5(
            $this->config['Catalog']['host'] . '|'
            . $this->config['Catalog']['proxy_user']
        );
        $factory = $this->sessionFactory;
        $this->sessionCache = $factory($namespace);
    }

    /**
     * Get Status
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id The record id to retrieve the holdings for
     *
     * @return array An associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber.
     */
    public function getStatus($id)
    {
        return $this->getItemStatusesForBib($id, false);
    }

    /**
     * Get Statuses
     *
     * This is responsible for retrieving the status information for a
     * collection of records.
     *
     * @param array $ids The array of record ids to retrieve the status for
     *
     * @return mixed     An array of getStatus() return values on success.
     */
    public function getStatuses($ids)
    {
        $items = [];
        foreach ($ids as $id) {
            $items[] = $this->getItemStatusesForBib($id, false);
        }
        return $items;
    }

    /**
     * Get Holding
     *
     * This is responsible for retrieving the holding information of a certain
     * record.
     *
     * @param string $id      The record id to retrieve the holdings for
     * @param array  $patron  Patron data
     * @param array  $options Extra options (not currently used)
     *
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber, duedate,
     * number, barcode.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getHolding($id, array $patron = null, array $options = [])
    {
        return $this->getItemStatusesForBib($id, true);
    }

    /**
     * Get Purchase History
     *
     * This is responsible for retrieving the acquisitions history data for the
     * specific record (usually recently received issues of a serial).
     *
     * @param string $id The record id to retrieve the info for
     *
     * @return mixed     An array with the acquisitions data on success.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getPurchaseHistory($id)
    {
        return [];
    }

    /**
     * Get New Items
     *
     * Retrieve the IDs of items recently added to the catalog.
     *
     * @param int $page    Page number of results to retrieve (counting starts at 1)
     * @param int $limit   The size of each page of results to retrieve
     * @param int $daysOld The maximum age of records to retrieve in days (max. 30)
     * @param int $fundId  optional fund ID to use for limiting results (use a value
     * returned by getFunds, or exclude for no limit); note that "fund" may be a
     * misnomer - if funds are not an appropriate way to limit your new item
     * results, you can return a different set of values from getFunds. The
     * important thing is that this parameter supports an ID returned by getFunds,
     * whatever that may mean.
     *
     * @return array       Associative array with 'count' and 'results' keys
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getNewItems($page, $limit, $daysOld, $fundId = null)
    {
        $pageSize = $limit ?? 50;
        $offset = $page ? ($page - 1) * $pageSize : 0;

        $params = [
            'limit' => $limit,
            'offset' => $offset
        ];

        if (int($daysOld) > 0) {
            $params['maxAge'] = $daysOld . ' days';
        }

        $result = $this->makeRequest(
            ['courses','public_role_users'],
            $params,
            'GET'
        );

        $ids = [];
        foreach ($result as $i) {
            $ids[] = $i['id'];
        }

        return ['count' => count($ids), 'results' => $ids];
    }

    /**
     * Get Instructors
     *
     * Obtain a list of instructors for use in limiting the reserves list.
     *
     * @throws ILSException
     * @return array An associative array with key = ID, value = name.
     */
    public function getInstructors()
    {
        $result = $this->makeRequest(
            ['courses','public_role_users'],
            [ ],
            'GET'
        );

        $instructors = [];
        foreach ($result as $u) {
            $pre = $u['pref_prefix'] ?? $u['prefix'];
            $lname = $u['pref_family_name'] ?? $u['family_name'];
            $suf = $u['pref_suffix'] ?? $u['suffix'];
            $instructors[$u['patron_id']] = $pre . ' ' . $lname . ' ' . $suf . ' (' . $u['usr_role'] .')';
        }

        return $instructors;
    }

    /**
     * Get Courses
     *
     * Obtain a list of courses for use in limiting the reserves list.
     *
     * @throws ILSException
     * @return array An associative array with key = ID, value = name.
     */
    public function getCourses()
    {
        $result = $this->makeRequest(
            ['courses'],
            [ ],
            'GET'
        );

        $courses = [];
        foreach ($result as $u) {
            $name = $u['name'] . ' - ' .$u['course_number'];
            if (!empty($u['section_number'])) {
                $name = $name . ' - ' .$u['section_number'];
            }
            $name = $name . ' (' . $u['owning_lib']['name'] . ')';
            $courses[$u['id']] = $name;
        }

        return $courses;
    }

    /**
     * Find Reserves
     *
     * Obtain information on course reserves.
     *
     * @param string $course ID from getCourses (empty string to match all)
     * @param string $inst   ID from getInstructors (empty string to match all)
     * @param string $dept   ID from getDepartments (empty string to match all)
     *
     * @return mixed An array of associative arrays representing reserve items.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function findReserves($course, $inst, $dept)
    {
        $result = $this->makeRequest(
            ['course', $course, 'materials'],
            [ ],
            'GET'
        );

        $mats = [];
        foreach ($result as $m) {
            $mats[] = [
                'BIB_ID' => $m['record']['id'],
                'COURSE_ID' => $m['course'],
            ];
        }

        return $mats;
    }

    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string $username The patron username
     * @param string $password The patron password
     *
     * @return mixed           Associative array of patron info on successful login,
     * null on unsuccessful login.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function patronLogin($username, $password)
    {
        // If we are using a patron-specific access grant, we can bypass
        // authentication as the credentials are verified when the access token is
        // requested.
        $patron = [
            'cat_username' => $username,
            'cat_password' => $password
        ];
        if ($this->renewAccessToken($patron)) {
            $patron = $this->getMyProfile($patron);
            if (!$patron) {
                return null;
            }
        }

        return $patron;
    }

    /**
     * Check whether the patron is blocked from placing requests (holds/ILL/SRR).
     *
     * @param array $patron Patron data from patronLogin().
     *
     * @return mixed A boolean false if no blocks are in place and an array
     * of block reasons if blocks are in place
     */
    public function getRequestBlocks($patron)
    {
        return $this->getPatronBlocks($patron);
    }

    /**
     * Check whether the patron has any blocks on their account.
     *
     * @param array $patron Patron data from patronLogin().
     *
     * @return mixed A boolean false if no blocks are in place and an array
     * of block reasons if blocks are in place
     */
    public function getAccountBlocks($patron)
    {
        return $this->getPatronBlocks($patron);
    }

    /**
     * Get Patron Profile
     *
     * This is responsible for retrieving the profile for a specific patron.
     *
     * @param array $patron The patron array
     *
     * @throws ILSException
     * @return array        Array of the patron's profile data on success.
     */
    public function getMyProfile($patron)
    {
        $result = $this->makeRequest(
            ['self', 'me'],
            [ ],
            'GET',
            $patron
        );

        if (empty($result)) {
            return [];
        }
        $address = '';
        $address2 = '';
        $zip = '';
        $city = '';
        $country = '';
        if (!empty($result['addresses'][0]['street1'])) {
            $address = $result['addresses'][0]['street1'];
            $address2 = $result['addresses'][0]['street2'];
            $zip = $result['addresses'][0]['post_code'];
            $city = $result['addresses'][0]['city'] . ', ' . $result['addresses'][0]['state'];
            $country = $result['addresses'][0]['country'];
        }
        $expirationDate = !empty($result['expire_date'])
                ? $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    $result['expire_date']
                ) : '';
        $dob = $this->dateConverter->convertToDisplayDate(
            'Y-m-d',
            $result['dob']
        );
        return [
            'cat_username' => $patron['cat_username'],
            'cat_password' => $patron['cat_password'],
            'firstname' => $result['first_given_name'],
            'lastname' => $result['family_name'],
            'phone' => $result['day_phone'],
            'email' => $result['email'],
            'address1' => $address,
            'address2' => $address2,
            'zip' => $zip,
            'city' => $city,
            'country' => $country,
            'expiration_date' => $expirationDate,
            'home_ou' => $result['home_ou'],
            'birthdate' => $dob
        ];
    }

    /**
     * Get Patron Transactions
     *
     * This is responsible for retrieving all transactions (i.e. checked out items)
     * by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     * @param array $params Parameters
     *
     * @throws DateException
     * @throws ILSException
     * @return array        Array of the patron's transactions on success.
     */
    public function getMyTransactions($patron, $params = [])
    {
        $result = $this->makeRequest(
            ['self', 'checkouts'],
            [ ],
            'GET',
            $patron
        );

        $transactions = [];
        foreach ($result as $entry) {
            $rec = $entry['record']; // mvr
            $cp = $entry['copy']; // acp
            $circ = $entry['circ']; // circ

            $transaction = [
                'id' => $rec['doc_id'],
                'checkout_id' => $circ['id'],
                'item_id' => $cp['id'],
                'barcode' => $cp['barcode'],
                'title' => $rec['title'],
                'publication_year' => $rec['pubdate'],
                'isbn' => $rec['isbn'],
                'duedate' => $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    $circ['due_date']
                ),
                'renewLimit' => $circ['renewal_remaining'],
                'renewable' => $circ['renewal_remaining'] > 0
            ];
            $transactions[] = $transaction;
        }

        return $transactions;
    }

    /**
     * Get Renew Details
     *
     * @param array $checkOutDetails An array of item data
     *
     * @return string Data for use in a form field
     */
    public function getRenewDetails($checkOutDetails)
    {
        return $checkOutDetails['item_id'] . '|' . $checkOutDetails['checkout_id'];
    }

    /**
     * Renew My Items
     *
     * Function for attempting to renew a patron's items.  The data in
     * $renewDetails['details'] is determined by getRenewDetails().
     *
     * @param array $renewDetails An array of data required for renewing items
     * including the Patron ID and an array of renewal IDS
     *
     * @return array              An array of renewal information keyed by item ID
     */
    public function renewMyItems($renewDetails)
    {
        $patron = $renewDetails['patron'];
        $finalResult = ['details' => []];

        foreach ($renewDetails['details'] as $details) {
            [$itemId, $checkoutId] = explode('|', $details);
            $result = $this->makeRequest(
                ['self', 'checkout', $checkoutId, 'renewal'],
                [],
                'POST',
                $patron
            );
            if ($result['errors'] > 0) {
                $msg = $result['result'][0]['desc'] ?? $result['result'][0]['textcode'];
                $finalResult['details'][$itemId] = [
                    'item_id' => $itemId,
                    'success' => false,
                    'sysMessage' => $msg
                ];
            } else {
                $newDate = $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    $result['result'][0]['payload']['circ']['due_date']
                );
                $finalResult['details'][$itemId] = [
                    'item_id' => $itemId,
                    'success' => true,
                    'new_date' => $newDate
                ];
            }
        }
        return $finalResult;
    }

    /**
     * Get Patron Transaction History
     *
     * This is responsible for retrieving all historic transactions (i.e. checked
     * out items) by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     * @param array $params Parameters
     *
     * @throws DateException
     * @throws ILSException
     * @return array        Array of the patron's historic transactions on success.
     */
    public function getMyTransactionHistory($patron, $params)
    {
        $pageSize = $params['limit'] ?? 50;
        $offset = isset($params['page']) ? ($params['page'] - 1) * $pageSize : 0;
        $sortOrder = isset($params['sort']) && 'checkout asc' === $params['sort']
            ? 'asc' : 'desc';

        $result = $this->makeRequest(
            ['self', 'checkouts', 'history'],
            [
                'sort' => $sortOrder,
                'limit' => $pageSize,
                'offset' => $offset
            ],
            'GET',
            $patron
        );

        $transactions = [];
        foreach ($result as $entry) {
            $rec = $entry['record']; // mvr
            $cp = $entry['copy']; // acp
            $circ = $entry['circ']; // circ

            $transaction = [
                'id' => $rec['id'],
                'checkout_id' => $circ['id'],
                'item_id' => $cp['id'],
                'barcode' => $cp['barcode'],
                'title' => $rec['title'],
                'publication_year' => $rec['pubdate'],
                'isbn' => $rec['isbn'],
                'returnDate' => $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    $circ['checkin_time']
                ),
                'checkoutDate' => $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    $circ['xact_start']
                ),
                'dueDate' => $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    $circ['due_date']
                )
            ];
            $transactions[] = $transaction;
        }

        return $transactions;
    }

    /**
     * Get Patron Holds
     *
     * This is responsible for retrieving all holds by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws DateException
     * @throws ILSException
     * @return array        Array of the patron's holds on success.
     *
     * Hold status codes:
     * -1 on error (for now),
     * 1 for 'waiting for copy to become available',
     * 2 for 'waiting for copy capture',
     * 3 for 'in transit',
     * 4 for 'arrived',
     * 5 for 'hold-shelf-delay'
     * 6 for 'canceled'
     * 7 for 'suspended'
     * 8 for 'captured, on wrong hold shelf'
     * 9 for 'fulfilled'
     */
    public function getMyHolds($patron)
    {
        $result = $this->makeRequest(
            ['self', 'holds'],
            [],
            'GET',
            $patron
        );
        $holds = [];
        foreach ($result as $e) {
            $h = $e['hold'];
            $mvr = $e['mvr'];
            $holds[] = [
                'id' => $e['bre_id'],
                'reqnum' => $h['id'],
                'item_id' => $h['current_copy'],
                'location' => $h['pickup_lib'],
                'create' => $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    $h['request_time']
                ),
                'expire' => $h['expire_time'] ? $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    $h['expire_time']
                ) : '',
                'last_pickup_date' => $h['shelf_expire_time'] ? $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    $h['shelf_expire_time']
                ) : '',
                'position' => $e['queue_position'] . ' / ' . $e['total_holds'],
                'available' => $e['status'] === 4,
                'in_transit' => $e['status'] === 3,
                'volume' => $e['volume']['label'],
                'isbn' => $mvr['isbn'],
                'publication_year' => $mvr['pubdate'],
                'title' => $mvr['title'],
                'frozen' => $e['status'] === 7,
                'frozenThrough' => $h['thaw_date'] ? $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    $h['thaw_date']
                ) : '',
                'cancel_details' => in_array($e['status'], [1,2,3,4,5,7,8]) ? $h['id'] : '',
                'updateDetails' => in_array($e['status'], [1,2,3,4,5,7,8]) ? $h['id'] : ''
            ];
        }
        return $holds;
    }

    public function getCancelHoldDetails($hold, $patron = [])
    {
        return $hold['reqnum'] . '';
    }

    /**
     * Cancel Holds
     *
     * Attempts to Cancel a hold. The data in $cancelDetails['details'] is taken from
     * holds' cancel_details field.
     *
     * @param array $cancelDetails An array of item and patron data
     *
     * @return array               An array of data on each request including
     * whether or not it was successful and a system message (if available)
     */
    public function cancelHolds($cancelDetails)
    {
        $details = $cancelDetails['details'];
        $patron = $cancelDetails['patron'];
        $count = 0;
        $response = [];

        foreach ($details as $holdId) {
            $result = $this->makeRequest(
                ['self', 'hold', $holdId],
                [],
                'DELETE',
                $patron
            );

            if ($result['errors'] !== 0) {
                $msg = $this->formatErrorMessage(
                    $result['desc'] ?? $result['name']
                );
                $response[$holdId] = [
                    'item_id' => $holdId,
                    'success' => false,
                    'status' => 'hold_cancel_fail',
                    'sysMessage' => $msg
                ];
            } else {
                $response[$holdId] = [
                    'item_id' => $holdId,
                    'success' => true,
                    'status' => 'hold_cancel_success'
                ];
                ++$count;
            }
        }
        return ['count' => $count, 'items' => $response];
    }

    /**
     * Update holds
     *
     * This is responsible for changing the status of hold requests
     *
     * @param array $holdsDetails The details identifying the holds
     * @param array $fields       An associative array of fields to be updated
     * @param array $patron       Patron array
     *
     * @return array Associative array of the results
     */
    public function updateHolds(
        array $holdsDetails,
        array $fields,
        array $patron
    ): array {
        $results = [];
        foreach ($holdsDetails as $requestId) {
            $updateFields = [];
            if (isset($fields['frozen'])) {
                $updateFields['frozen'] = $fields['frozen'];
            }
            if (isset($fields['requiredByDate'])) {
                $updateFields['expire_time'] = $fields['requiredByDate'];
            }
            if (isset($fields['frozenThrough'])) {
                $updateFields['thaw_date'] = $fields['frozenThrough'];
            }
            if (isset($fields['pickUpLocation'])) {
                $updateFields['pickup_lib'] = $fields['pickUpLocation'];
            }

            $result = $this->makeRequest(
                ['self', 'hold', $requestId],
                json_encode($updateFields),
                'PATCH',
                $patron
            );

            if ($result['errors'] !== 0) {
                $results[$requestId] = [
                    'success' => false,
                    'status' => $this->formatErrorMessage(
                        $result['desc'] ?? $result['name']
                    )
                ];
            } else {
                $results[$requestId] = [
                    'success' => true
                ];
            }
        }

        return $results;
    }

    /**
     * Get Pick Up Locations
     *
     * This is responsible for gettting a list of valid library locations for
     * holds / recall retrieval
     *
     * @param array $patron      Patron information returned by the patronLogin
     * method.
     * @param array $holdDetails Optional array, only passed in when getting a list
     * in the context of placing or editing a hold.  When placing a hold, it contains
     * most of the same values passed to placeHold, minus the patron data.  When
     * editing a hold it contains all the hold information returned by getMyHolds.
     * May be used to limit the pickup options or may be ignored.  The driver must
     * not add new options to the return array based on this data or other areas of
     * VuFind may behave incorrectly.
     *
     * @throws ILSException
     * @return array        An array of associative arrays with locationID and
     * locationDisplay keys
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getPickUpLocations($patron = false, $holdDetails = null)
    {
        $result = $this->makeRequest(
            ['holds', 'pickupLocations'],
            [],
            'GET',
            $patron
        );

        $locations = [];
        foreach ($result as $entry) {
            $locations[] = [
                'locationID' => $entry['id'],
                'locationDisplay' => $entry['name']
            ];
        }

        usort($locations, [$this, 'pickupLocationSortFunction']);
        return $locations;
    }

    /**
     * Get Default Pick Up Location
     *
     * Returns the default pick up location
     *
     * @param array $patron      Patron information returned by the patronLogin
     * method.
     * @param array $holdDetails Optional array, only passed in when getting a list
     * in the context of placing a hold; contains most of the same values passed to
     * placeHold, minus the patron data.  May be used to limit the pickup options
     * or may be ignored.
     *
     * @return false|string      The default pickup location for the patron or false
     * if the user has to choose.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getDefaultPickUpLocation($patron = false, $holdDetails = null)
    {
        if ($patron) {
            return $patron['home_ou'];
        }
        return $this->defaultPickUpLocation;
    }

    /**
     * Check if request is valid
     *
     * This is responsible for determining if an item is requestable
     *
     * @param string $id     The Bib ID
     * @param array  $data   An Array of item data
     * @param patron $patron An array of patron data
     *
     * @return bool True if request is valid, false if not
     */
    public function checkRequestIsValid($id, $data, $patron)
    {
        if ($this->getPatronBlocks($patron)) {
            return false;
        }
        // TODO: want to implement title-hold-is-possible via api
        return true;
    }

    /**
     * Place Hold
     *
     * Attempts to place a hold or recall on a particular item and returns
     * an array with result details or throws an exception on failure of support
     * classes
     *
     * @param array $holdDetails An array of item and patron data
     *
     * @throws ILSException
     * @return mixed An array of data on the request including
     * whether or not it was successful and a system message (if available)
     */
    public function placeHold($holdDetails)
    {
        $patron = $holdDetails['patron'];
        $pickUpLocation = !empty($holdDetails['pickUpLocation'])
            ? $holdDetails['pickUpLocation'] : $this->defaultPickUpLocation;

        $level = isset($holdDetails['level']) && !empty($holdDetails['level'])
            ? $holdDetails['level'] : 'title';

        $itemId = $holdDetails['item_id'];
        $bibId = $holdDetails['id'];

        $request = [
            'pickup_lib' => $pickUpLocation
        ];

        if ($level == 'title') {
            $request['bib'] = $bibId;
        } else {
            $request['copy'] = $itemId;
        }

        // Convert last interest date from Display Format to Evergreen's required format
        try {
            $lastInterestDate = $this->dateConverter->convertFromDisplayDate(
                'Y-m-d',
                $holdDetails['requiredBy']
            );
            $request['expire_time'] = $lastInterestDate;
        } catch (DateException $e) {
            // Hold Date is invalid
            return $this->holdError('hold_date_invalid');
        }

        if ($level == 'copy' && empty($itemId)) {
            throw new ILSException("Hold level is 'copy', but item ID is empty");
        }

        try {
            $checkTime = $this->dateConverter->convertFromDisplayDate(
                'U',
                $holdDetails['requiredBy']
            );
            if (!is_numeric($checkTime)) {
                throw new DateException('Result should be numeric');
            }
        } catch (DateException $e) {
            throw new ILSException('Problem parsing required by date.', 0, $e);
        }

        if (time() > $checkTime) {
            // Hold Date is in the past
            return $this->holdError('hold_date_past');
        }

        // Make sure pickup location is valid
        if (!$this->pickUpLocationIsValid($pickUpLocation, $patron, $holdDetails)) {
            return $this->holdError('hold_invalid_pickup');
        }

        $result = $this->makeRequest(
            ['self', 'holds'],
            json_encode($request),
            'POST',
            $patron
        );

        if ($result['error'] > 0) {
            return $this->holdError($result['result']['desc'] ?? $result['result']['name']);
        }
        return ['success' => true];
    }

    /**
     * Get Patron Fines
     *
     * This is responsible for retrieving all fines by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws DateException
     * @throws ILSException
     * @return array        Array of the patron's fines on success.
     */
    public function getMyFines($patron)
    {
        $result = $this->makeRequest(
            ['self', 'transactions', 'have_balance'],
            [ ],
            'GET',
            $patron
        );

        foreach ($result as $entry) {
            $x = $entry['transaction'];
            $circ = $entry['circ'];
            $acp = $entry['copy'];
            $mvr = $entry['record'];

            $bibId = null;
            $title = null;
            $pubdate = null;
            $checkout = null;
            $duedate = null;

            if ($circ) {
                $checkout = $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    $circ['xact_start']
                );
                $duedate = $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    $circ['due_date']
                );
                $itemId = $acp['id'];
                $title = $mvr['title'];
                $pubdate = $mvr['pubdate'];
            }

            $fines[] = [
                'amount' => $x['total_owed'] * 100,
                'fine' => $x['last_billing_type'],
                'balance' => $x['balance_owed'] * 100,
                'checkout' => $checkout,
                'duedate' => $duedate,
                'createdate' => $this->dateConverter->convertToDisplayDate(
                    'Y-m-d',
                    $x['last_billing_ts']
                ),
                'id' => $bibId,
                'title' => $title,
                'publication_year' => $title
            ];
        }
        return $fines;
    }

    /**
     * Change Password
     *
     * Attempts to change patron password (PIN code)
     *
     * @param array $details An array of patron id and old and new password:
     *
     * 'patron'      The patron array from patronLogin
     * 'oldPassword' Old password
     * 'newPassword' New password
     *
     * @return array An array of data on the request including
     * whether or not it was successful and a system message (if available)
     */
    public function changePassword($details)
    {
        // Force new login
        $this->sessionCache->accessTokenPatron = '';
        $patron = $this->patronLogin(
            $details['patron']['cat_username'],
            $details['oldPassword']
        );
        if (null === $patron) {
            return [
                'success' => false, 'status' => 'authentication_error_invalid'
            ];
        }

        $request = [
            'password'         => $details['newPassword'],
            'current_password' => $details['oldPassword']
        ];

        $result = $this->makeRequest(
            ['self', 'me'],
            json_encode($request),
            'PATCH',
            $patron
        );

        if ($result['password']['success'] < 1) {
            return [
                'success' => false,
                'status' => $result['password']['error']['desc'] ?? $result['password']['error']['textcode']
            ];
        }
        return ['success' => true, 'status' => 'change_password_ok'];
    }

    /**
     * Public Function which retrieves renew, hold and cancel settings from the
     * driver ini file.
     *
     * @param string $function The name of the feature to be checked
     * @param array  $params   Optional feature-specific parameters (array)
     *
     * @return array An array with key-value pairs.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getConfig($function, $params = null)
    {
        if ('getMyTransactions' === $function) {
            return [
                'max_results' => 100
            ];
        }
        if ('getMyTransactionHistory' === $function) {
            if (empty($this->config['TransactionHistory']['enabled'])) {
                return false;
            }
            return [
                'max_results' => 100,
                'sort' => [
                    'checkout desc' => 'sort_checkout_date_desc',
                    'checkout asc' => 'sort_checkout_date_asc'
                ],
                'default_sort' => 'checkout desc'
            ];
        }
        return $this->config[$function] ?? false;
    }

    /**
     * Helper method to determine whether or not a certain method can be
     * called on this driver.  Required method for any smart drivers.
     *
     * @param string $method The name of the called method.
     * @param array  $params Array of passed parameters
     *
     * @return bool True if the method can be called with the given parameters,
     * false otherwise.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function supportsMethod($method, $params)
    {
        return is_callable([$this, $method]);
    }

    /**
     * Make Request
     *
     * Makes a request to the Evergreen REST API
     *
     * @param array  $hierarchy    Array of values to embed in the URL path of the
     * request
     * @param array  $params       A keyed array of query data
     * @param string $method       The http request method to use (Default is GET)
     * @param array  $patron       Patron information, if available
     * @param bool   $returnStatus Whether to return HTTP status code and response
     * as a keyed array instead of just the response
     *
     * @throws ILSException
     * @return mixed JSON response decoded to an associative array, an array of HTTP
     * status code and JSON response when $returnStatus is true or null on
     * authentication error when using patron-specific access
     */
    protected function makeRequest(
        $hierarchy,
        $params = false,
        $method = 'GET',
        $patron = false,
        $returnStatus = false
    ) {
        // Clear current access token if it's not specific to the given patron
        if ($patron && $this->sessionCache->accessTokenPatron != $patron['cat_username']) {
            $this->sessionCache->accessToken = null;
        }

        // Renew authentication token as necessary
        if (null === $this->sessionCache->accessToken) {
            if (!$this->renewAccessToken($patron)) {
                return null;
            }
        }

        // Set up the request
        $apiUrl = $this->config['Catalog']['host']; // https://example.com/openapi3/v1

        // Add hierarchy
        foreach ($hierarchy as $value) {
            $apiUrl .= '/' . urlencode($value);
        }

        // Create proxy request
        $client = $this->createHttpClient($apiUrl);

        // Add params
        if ($method == 'GET') {
            $client->setParameterGet($params);
        } else {
            if (is_string($params)) {
                $client->getRequest()->setContent($params);
            } else {
                $client->setParameterPost($params);
            }
        }

        // Set authorization header
        $headers = $client->getRequest()->getHeaders();
        $headers->addHeaderLine(
            'Authorization',
            "Bearer {$this->sessionCache->accessToken}"
        );
        if (is_string($params)) {
            $headers->addHeaderLine('Content-Type', 'application/json');
        }

        $locale = $this->getTranslatorLocale();
        if ($locale != 'en') {
            $locale .= ', en;q=0.8';
        }
        $headers->addHeaderLine('Accept-Language', $locale);

        // Send request and retrieve response
        $startTime = microtime(true);
        try {
            $response = $client->setMethod($method)->send();
        } catch (\Exception $e) {
            $params = $method == 'GET'
                ? $client->getRequest()->getQuery()->toString()
                : $client->getRequest()->getPost()->toString();
            $this->error(
                "$method request for '$apiUrl' with params '$params' and contents '"
                . $client->getRequest()->getContent() . "' caused exception: "
                . $e->getMessage()
            );
            throw new ILSException('Problem with Evergreen REST API.', 0, $e);
        }
        // If we get a 401, we need to renew the access token and try again
        if ($response->getStatusCode() == 401) {
            if (!$this->renewAccessToken($patron)) {
                return null;
            }
            $client->getRequest()->getHeaders()->addHeaderLine(
                'Authorization',
                "Bearer {$this->sessionCache->accessToken}"
            );
            $response = $client->send();
        }
        $result = $response->getBody();

        $this->debug(
            '[' . round(microtime(true) - $startTime, 4) . 's]'
            . " $method request $apiUrl" . PHP_EOL . 'response: ' . PHP_EOL
            . $result
        );

        // Handle errors as complete failures only if the API call didn't return
        // valid JSON that the caller can handle
        $decodedResult = json_decode($result, true);
        if (!$response->isSuccess() && null === $decodedResult) {
            $params = $method == 'GET'
                ? $client->getRequest()->getQuery()->toString()
                : $client->getRequest()->getPost()->toString();
            $this->error(
                "$method request for '$apiUrl' with params '$params' and contents '"
                . $client->getRequest()->getContent() . "' failed: "
                . $response->getStatusCode() . ': ' . $response->getReasonPhrase()
                . ', response content: ' . $response->getBody()
            );
            throw new ILSException('Problem with Evergreen REST API.');
        }

        return $returnStatus
            ? [
                'statusCode' => $response->getStatusCode(),
                'response' => $decodedResult
            ] : $decodedResult;
    }

    /**
     * Renew the API access token and store it in the cache.
     * Throw an exception if there is an error.
     *
     * @param array $patron Patron information, if available
     *
     * @return bool True on success, false on patron login failure
     * @throws ILSException
     */
    protected function renewAccessToken($patron = false)
    {
        $params = [];
        if ($patron) {
            $params['u'] = $patron['cat_username'];
            $params['p'] = $patron['cat_password'];
        } else {
            $params['u'] = $this->config['Catalog']['proxy_user'];
            $params['p'] = $this->config['Catalog']['proxy_password'];
        }

        // Set up the request
        $apiUrl = $this->config['Catalog']['host'] . '/self/auth';

        // Create proxy request
        $client = $this->createHttpClient($apiUrl);
        $client->setParameterGet($params);

        // Send request and retrieve response
        $startTime = microtime(true);
        try {
            $response = $client->setMethod('GET')->send();
        } catch (\Exception $e) {
            $this->error(
                "GET request for '$apiUrl' caused exception: "
                . $e->getMessage()
            );
            throw new ILSException('Problem with Evergreen REST API.', 0, $e);
        }

        if (!$response->isSuccess()) {
            $this->error(
                "GET request for '$apiUrl' with contents '"
                . $client->getRequest()->getContent() . "' failed: "
                . $response->getStatusCode() . ': ' . $response->getReasonPhrase()
                . ', response content: ' . $response->getBody()
            );
            throw new ILSException('Problem with Evergreen REST API.');
        }
        $result = $response->getBody();

        $this->debug(
            '[' . round(microtime(true) - $startTime, 4) . 's]'
            . " GET request $apiUrl" . PHP_EOL . 'response: ' . PHP_EOL
            . $result
        );

        $json = json_decode($result, true);
        $this->sessionCache->accessToken = $json['token'];
        $this->sessionCache->accessTokenPatron = $patron
            ? $patron['cat_username'] : null;
        return true;
    }

    /**
     * Create a HTTP client
     *
     * @param string $url Request URL
     *
     * @return \Laminas\Http\Client
     */
    protected function createHttpClient($url)
    {
        $client = $this->httpService->createClient($url);

        // Set timeout value
        $timeout = $this->config['Catalog']['http_timeout'] ?? 30;
        $client->setOptions(
            ['timeout' => $timeout, 'useragent' => 'VuFind', 'keepalive' => true]
        );

        // Set Accept header
        $client->getRequest()->getHeaders()->addHeaderLine(
            'Accept',
            'application/json'
        );

        return $client;
    }

    /**
     * Add instance-specific context to a cache key suffix to ensure that
     * multiple drivers don't accidentally share values in the cache.
     *
     * @param string $key Cache key suffix
     *
     * @return string
     */
    protected function formatCacheKey($key)
    {
        return 'EvergreenRest-' . md5($this->config['Catalog']['host'] . "|$key");
    }

    /**
     * Get Item Statuses
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id            The record id to retrieve the holdings for
     * @param bool   $checkHoldings Whether to check holdings records
     *
     * @return array An associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber.
     */
    protected function getItemStatusesForBib($id, $checkHoldings)
    {
        $holdingsTree = $this->makeRequest(
            ['bibs', $id, 'holdings'],
            [],
            'GET'
        );

        $holdingsData = [];
        foreach ($holdingsTree as $acn) {
            foreach ($acn['copies'] as $acp) {
                $holdingsData[] = [
                    'id' => $id,
                    'item_id' => $acp['id'],
                    'availability' => $acp['status']['is_available'],
                    'status' => $this->mapStatusCode($acp['status']['id']),
                    'barcode' => $acp['barcode'],
                    'number' => $acp['barcode'],
                    'is_holdable' => $acp['holdable'] && $acp['location']['holdable'] && $acp['status']['holdable'],
                    'hold_type' => 'hold',
                    'location' => $acp['location']['name'],
                    'reserve' => $acp['status']['id'] == 12 ? 'Y' : 'N',
                    'callnumber' => $acn['label'],
                    'callnumber_prefix' => $acn['prefix']['label'],
                    'acp' => $acp,
                    'acn' => $acn
                ];
            }
        }

        return $holdingsData;
    }

    /**
     * Status item sort function
     *
     * @param array $a First status record to compare
     * @param array $b Second status record to compare
     *
     * @return int
     */
    protected function statusSortFunction($a, $b)
    {
        $result = strcmp($a['location'], $b['location']);
        if ($result === 0 && $this->sortItemsByEnumChron) {
            $result = strnatcmp($b['number'] ?? '', $a['number'] ?? '');
        }
        if ($result === 0) {
            $result = $a['sort'] - $b['sort'];
        }
        return $result;
    }

    /**
     * Get the human-readable equivalent of a status code.
     *
     * @param string $code    Code to map
     * @param string $default Default value if no mapping found
     *
     * @return string
     */
    protected function mapStatusCode($code, $default = null)
    {
        return trim($this->itemStatusMappings[$code] ?? $default ?? $code);
    }

    /**
     * Get patron's blocks, if any
     *
     * @param array $patron Patron
     *
     * @return mixed        A boolean false if no blocks are in place and an array
     * of block reasons if blocks are in place
     */
    protected function getPatronBlocks($patron)
    {
        $result = $this->makeRequest(
            ['self', 'standing_penalties'],
            [],
            'GET',
            $patron
        );

        $blockReason = [];
        foreach ($result as $sp) {
            if ($sp['standing_penalty']['block_list']) {
               $blockReason[] = $sp['usr_message']['message'] ?? $sp['standing_penalty']['label'];
            }
        }
        return empty($blockReason) ? false : $blockReason;
    }

    /**
     * Pickup location sort function
     *
     * @param array $a First pickup location record to compare
     * @param array $b Second pickup location record to compare
     *
     * @return int
     */
    protected function pickupLocationSortFunction($a, $b)
    {
        $result = strcmp($a['locationDisplay'], $b['locationDisplay']);
        if ($result == 0) {
            $result = $a['locationID'] - $b['locationID'];
        }
        return $result;
    }

    /**
     * Is the selected pickup location valid for the hold?
     *
     * @param string $pickUpLocation Selected pickup location
     * @param array  $patron         Patron information returned by the patronLogin
     * method.
     * @param array  $holdDetails    Details of hold being placed
     *
     * @return bool
     */
    protected function pickUpLocationIsValid($pickUpLocation, $patron, $holdDetails)
    {
        $pickUpLibs = $this->getPickUpLocations($patron, $holdDetails);
        foreach ($pickUpLibs as $location) {
            if ($location['locationID'] == $pickUpLocation) {
                return true;
            }
        }
        return false;
    }

    /**
     * Hold Error
     *
     * Returns a Hold Error Message
     *
     * @param string $msg An error message string
     *
     * @return array An array with a success (boolean) and sysMessage key
     */
    protected function holdError($msg)
    {
        $msg = $this->formatErrorMessage($msg);
        return [
            'success' => false,
            'sysMessage' => $msg
        ];
    }

    /**
     * Format an error message received from Evergreen
     *
     * @param string $msg An error message string
     *
     * @return string
     */
    protected function formatErrorMessage($msg)
    {
        return $msg;
    }

}
