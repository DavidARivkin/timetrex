<?php

/*********************************************************************************
 * TimeTrex is a Payroll and Time Management program developed by
 * TimeTrex Software Inc. Copyright (C) 2003 - 2014 TimeTrex Software Inc.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by
 * the Free Software Foundation with the addition of the following permission
 * added to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED
 * WORK IN WHICH THE COPYRIGHT IS OWNED BY TIMETREX, TIMETREX DISCLAIMS THE
 * WARRANTY OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more
 * details.
 *
 * You should have received a copy of the GNU Affero General Public License along
 * with this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 *
 * You can contact TimeTrex headquarters at Unit 22 - 2475 Dobbin Rd. Suite
 * #292 Westbank, BC V4T 2E9, Canada or at email address info@timetrex.com.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License
 * version 3, these Appropriate Legal Notices must retain the display of the
 * "Powered by TimeTrex" logo. If the display of the logo is not reasonably
 * feasible for technical reasons, the Appropriate Legal Notices must display
 * the words "Powered by TimeTrex".
 ********************************************************************************/

require_once( Environment::getBasePath() .'classes'. DIRECTORY_SEPARATOR .'adodb'. DIRECTORY_SEPARATOR .'adodb.inc.php');
require_once( Environment::getBasePath() .'classes'. DIRECTORY_SEPARATOR .'adodb'. DIRECTORY_SEPARATOR .'adodb-exceptions.inc.php');

//Use overloading to abstract $db and have calls directly to ADODB
if ( !isset($disable_database_connection) ) {
	if ( isset($config_vars['database']['type']) AND isset($config_vars['database']['host']) AND isset($config_vars['database']['user']) AND isset($config_vars['database']['password']) AND isset($config_vars['database']['database_name']) ) {
		try {
			if ( isset($config_vars['cache']['dir']) AND $config_vars['cache']['dir'] != '' ) {
				$ADODB_CACHE_DIR = $config_vars['cache']['dir'] . DIRECTORY_SEPARATOR;
			}

			$ADODB_GETONE_EOF = FALSE; //Make sure GetOne returns FALSE rather then NULL.
			if ( strpos( $config_vars['database']['host'], ',' ) !== FALSE ) {
				require_once( Environment::getBasePath() .'classes'. DIRECTORY_SEPARATOR .'adodb'. DIRECTORY_SEPARATOR .'adodb-loadbalancer.inc.php');
				if ( !isset($config_vars['database']['persistent_connections']) ) {
					$config_vars['database']['persistent_connections'] = FALSE;
				}

				$db = new ADOdbLoadBalancer();

				if ( Debug::getVerbosity() == 11 ) {
					$ADODB_OUTP = 'ADODBDebug';
					function ADODBDebug( $msg, $newline = TRUE ) {
						Debug::Text( html_entity_decode( strip_tags( $msg ) ), __FILE__, __LINE__, __METHOD__, 11);
						return TRUE;
					}
				}

				//Use comma separated database hosts, assuming the first is always the master, the rest are slaves.
				//Anything after the # is the weight. Username/password/database is assumed to be the same across all connections.
				//ie: 127.0.0.1:5433#10,127.0.0.2:5433#100,127.0.0.3:5433#120				
				$db_hosts = Misc::parseDatabaseHostString( $config_vars['database']['host'] );
				foreach( $db_hosts as $db_host_arr ) {
					Debug::Text( 'Adding DB Connection: Host: '. $db_host_arr[0] .' Type: '. $db_host_arr[1] .' Weight: '. $db_host_arr[2], __FILE__, __LINE__, __METHOD__, 1);
					if ( $db_host_arr[2] == 5 ) {
						$db_connection_obj = new ADOdbLoadBalancerConnection( $config_vars['database']['type'], $db_host_arr[1], $db_host_arr[2], (bool)$config_vars['database']['persistent_connections'], $db_host_arr[0], $config_vars['database']['user'].'9', $config_vars['database']['password'], $config_vars['database']['database_name'] );
					} else {
						$db_connection_obj = new ADOdbLoadBalancerConnection( $config_vars['database']['type'], $db_host_arr[1], $db_host_arr[2], (bool)$config_vars['database']['persistent_connections'], $db_host_arr[0], $config_vars['database']['user'], $config_vars['database']['password'], $config_vars['database']['database_name'] );
					}
					$db_connection_obj->getADODbObject()->SetFetchMode(ADODB_FETCH_ASSOC);
					$db_connection_obj->getADODbObject()->noBlobs = TRUE; //Optimization to tell ADODB to not bother checking for blobs in any result set.
					$db_connection_obj->getADODbObject()->fmtTimeStamp = "'Y-m-d H:i:s'";

					if ( Debug::getVerbosity() == 11 ) {
						//Use 1 instead of TRUE, so it only outputs some debugging and not things like backtraces for every cache read/write.
						//Set to 99 to get all debug output.
						$db_connection_obj->getADODbObject()->debug = 1;
					}

					if ( isset($config_vars['database']['disable_row_count']) AND $config_vars['database']['disable_row_count'] == TRUE ) {
						//Dont count rows for pagination, much faster. However two queries must be run to tell if we are at the last page or not.
						$db_connection_obj->getADODbObject()->pageExecuteCountRows = FALSE;
					}
					$db->addConnection( $db_connection_obj );
				}
				unset($db_hosts, $db_host_arr, $db_connection_obj);

				//Make sure when inserting times we always include the timezone.
				//UNLESS we're using MySQL, because MySQL can't store time stamps with time zones.
				if ( strncmp($config_vars['database']['type'], 'mysql', 5) == 0 ) {
					//Put MySQL into ANSI mode
					//READ COMMITTED mode is what PGSQL defaults to.
					//This should hopefully fix odd issues like hierarchy trees becoming corrupt.
					$db->setSessionInitSQL( 'SET SESSION sql_mode=\'ansi\'' );
					$db->setSessionInitSQL( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' );
				}				
			} else {
				//To enable PDO support. Type: pdo_pgsql or pdo_mysql
				//$dsn = $config_vars['database']['type'].'://'.$config_vars['database']['user'].':'.$config_vars['database']['password'].'@'.$config_vars['database']['host'].'/'.$config_vars['database']['database_name'].'?persist';
				//$db = ADONewConnection( $dsn );
				$db = ADONewConnection( $config_vars['database']['type'] );
				$db->SetFetchMode(ADODB_FETCH_ASSOC);
				if ( isset($config_vars['database']['persistent_connections']) AND $config_vars['database']['persistent_connections'] == TRUE ) {
					$db->PConnect( $config_vars['database']['host'], $config_vars['database']['user'], $config_vars['database']['password'], $config_vars['database']['database_name']);
				} else {
					$db->Connect( $config_vars['database']['host'], $config_vars['database']['user'], $config_vars['database']['password'], $config_vars['database']['database_name']);
				}
				$db->noBlobs = TRUE; //Optimization to tell ADODB to not bother checking for blobs in any result set.

				if ( Debug::getVerbosity() == 11 ) {
					$ADODB_OUTP = 'ADODBDebug';
					function ADODBDebug( $msg, $newline = TRUE ) {
						Debug::Text( html_entity_decode( strip_tags( $msg ) ), __FILE__, __LINE__, __METHOD__, 11);
						return TRUE;
					}

					//Use 1 instead of TRUE, so it only outputs some debugging and not things like backtraces for every cache read/write.
					//Set to 99 to get all debug output.
					$db->debug = 1;
				}

				//Make sure when inserting times we always include the timezone.
				//UNLESS we're using MySQL, because MySQL can't store time stamps with time zones.
				if ( strncmp($db->databaseType, 'mysql', 5) == 0 ) {
					$db->fmtTimeStamp = "'Y-m-d H:i:s'";
					//Put MySQL into ANSI mode
					//READ COMMITTED mode is what PGSQL defaults to.
					//This should hopefully fix odd issues like hierarchy trees becoming corrupt.
					$db->Execute('SET SESSION sql_mode=\'ansi\'');
					$db->Execute('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
				} else {
					//Use long timezone format because PostgreSQL 8.1 doesn't support some short names, like SGT,IST
					//Using "e" for the timezone fixes the Asia/Calcutta & IST bug where the two were getting confused.
					//We set the timezone in PostgreSQL like we do with MySQL, so 'e' shouldn't be required anymore.
					//$db->fmtTimeStamp = "'Y-m-d H:i:s e'";
					$db->fmtTimeStamp = "'Y-m-d H:i:s'";
				}

				if ( isset($config_vars['database']['disable_row_count']) AND $config_vars['database']['disable_row_count'] == TRUE ) {
					//Dont count rows for pagination, much faster. However two queries must be run to tell if we are at the last page or not.
					$db->pageExecuteCountRows = FALSE;
				}
			}
		} catch (Exception $e) {
			Debug::Text( 'Error connecting to the database!', __FILE__, __LINE__, __METHOD__, 1);
			throw new DBError($e);
		}

		//Global options for FastTree class.
		$fast_tree_options = array( 'db' => $db, 'table' => 'hierarchy_tree' );
		$fast_tree_user_group_options = array( 'db' => $db, 'table' => 'user_group_tree' );
		$fast_tree_qualification_group_options = array( 'db' => $db, 'table' => 'qualification_group_tree' );
		$fast_tree_kpi_group_options = array( 'db' => $db, 'table' => 'kpi_group_tree' );
		$fast_tree_job_group_options = array( 'db' => $db, 'table' => 'job_group_tree' );
		$fast_tree_job_item_group_options = array( 'db' => $db, 'table' => 'job_item_group_tree' );
		$fast_tree_client_group_options = array( 'db' => $db, 'table' => 'client_group_tree' );
		$fast_tree_product_group_options = array( 'db' => $db, 'table' => 'product_group_tree' );
		$fast_tree_document_group_options = array( 'db' => $db, 'table' => 'document_group_tree' );
	} else {
		Debug::Text( 'Database config options are not set... Unable to connect to database.', __FILE__, __LINE__, __METHOD__, 1);
		throw new DBError( new Exception );
	}
}

//Set timezone to system local timezone by default. This is so we sync up all timezones
//in the database (specifically MySQL) and PHP. This fixes timezone bugs
//mainly in maintenance scripts. We used to default this to just GMT, but that can cause additional problems in threaded environments.
//This must be run AFTER the database connection has been made to work properly.
if ( !isset($config_vars['other']['system_timezone']) OR ( isset($config_vars['other']['system_timezone']) AND $config_vars['other']['system_timezone'] == '' ) ) {
	$config_vars['other']['system_timezone'] = @date('e');
}
TTDate::setTimeZone( $config_vars['other']['system_timezone'] );
?>
