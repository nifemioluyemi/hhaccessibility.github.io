<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

function getPrimaryKeysToUpdate() {
	return [
		'user', 'review_comment', 'user_answer', 'user_location', 'location', 'user_role',
		'location_location_tag'
	];
}

function getFieldsToUpdate() {
	return [
		'location' => ['owner_user_id', 'creator_user_id'],
		'location_duplicate' => ['location_id'],
		'location_location_tag' => ['location_id'],
		'review_comment' => ['answered_by_user_id', 'location_id'],
		'user_answer' => ['answered_by_user_id', 'location_id'],
		'user_location' => ['user_id', 'location_id'],
		'user_question' => ['user_id'],
		'user_role' => ['user_id']
	];
}

function getAffectedTableNames() {
	$keys = array_keys(getFieldsToUpdate());
	
	return array_unique(array_merge(getPrimaryKeysToUpdate(), $keys));
}

function getAffectedFieldsForTable($tableName) {
	$queryTable = DB::table($tableName);
	$fieldNames = [];
	if (in_array($tableName, getPrimaryKeysToUpdate())) {
		$fieldNames []= 'id';
	}
	if (array_key_exists($tableName, getFieldsToUpdate())) {
		$fieldsToUpdate = getFieldsToUpdate()[$tableName];
		$fieldNames = array_merge($fieldsToUpdate, $fieldNames);
	}
	return $fieldNames;
}

/*
This migration converts various autoincrement primary ids to guids.

This change will help merge information gathered from seed data 
into our deployments.  This is how new information collected from 
our import tools will end up in our public deployments.
*/

function idToGuid($id) {
	$result = ''.$id; // decimal representation.
	while (strlen($result) < 12) {
		$result = '0'.$result;
	}
	return '00000000-0000-0000-0000-'.$result;
}


function getForeignTableFromForeignField($fieldName) {
	$otherTableName = $fieldName;
	if (strrpos($otherTableName, '_id') === strlen($otherTableName) - 3 ) {
		$otherTableName = substr($otherTableName, 0, strlen($otherTableName) - 3);
	}
	if (strpos($otherTableName, '_user') !== FALSE) {
		$otherTableName = 'user';
	}
	return $otherTableName;
}


class Test
{
	public static function convertData(string $tableName)
	{
		$queryTable = DB::table($tableName);
		$fieldNames = getAffectedFieldsForTable($tableName);
		foreach ($fieldNames as $fieldName) {
			$queryTable->update(['new_'.$fieldName => DB::raw("IF(".$fieldName." is null, null, concat('00000000-0000-0000-0000-', LPAD(".$fieldName.", 12, '0')))")]);
		}
	}

	public function addForeignKeyConstraints($tableName)
	{
		$fieldNames = getAffectedFieldsForTable($tableName);
        return function (Blueprint $table) use( &$fieldNames) {
			foreach ($fieldNames as $fieldName) {
				if( $fieldName !== 'id' ) {
					$otherTableName = getForeignTableFromForeignField($fieldName);
					$table->foreign($fieldName)->references('id')->on($otherTableName);
				}
			}
		};
	}
	
	public function dropOriginalFieldsAndRename($tableName)
	{
		$fieldNames = getAffectedFieldsForTable($tableName);
        return function (Blueprint $table) use( &$fieldNames) {
			foreach ($fieldNames as $fieldName) {
				if( $fieldName === 'id' ) {
					$table->integer($fieldName)->unsigned()->change();
					$table->dropPrimary();
				}
				// drop the column.
				$table->dropColumn($fieldName);
				$table->renameColumn('new_'.$fieldName, $fieldName);
				if( $fieldName === 'id' ) {
					$table->integer($fieldName)->unsigned()->change();
					$table->primary(['id']);
				}
			}
		};
	}

    public function convertIdToGuidAndDropForeignConstraints($tableName)
    {
		$fieldNames = getAffectedFieldsForTable($tableName);
        return function (Blueprint $table) use( &$fieldNames, &$tableName) {
			foreach ($fieldNames as $fieldName) {
				$table->uuid('new_' . $fieldName)->nullable();
				
				// Drop foreign key constraints on $fieldName.
				if( $fieldName !== 'id' && $fieldName !== '' ) {
					$constraintName = $tableName.'_'.$fieldName.'_foreign';
					$table->dropForeign($constraintName);
				}
			}
		};
    }

	public function undoChange($tableName)
	{
		$fieldNames = getAffectedFieldsForTable($tableName);
		$newFieldNames = [];
		foreach ($fieldNames as $fieldName) {
			$newFieldNames []= 'new_' . $fieldName;
		}
        return function (Blueprint $table) use( &$newFieldNames) {
			$table->dropColumn($newFieldNames);
		};
	}
}


class SwitchToGuids extends Migration
{
    public function up()
    {
		foreach (getAffectedTableNames() as $tableName) {
			$object = new Test();
			$function = $object->convertIdToGuidAndDropForeignConstraints($tableName);
			Schema::table($tableName, $function);
			Test::convertData($tableName);
		}
		echo "Finished adding new_ fields to all tables and converting data to them.\r\n";
		Schema::table('user_role', function(Blueprint $table) {
			$table->dropForeign('user_role_role_id_foreign');
			$table->dropUnique('user_role_role_id_user_id_unique');
		});
		echo "Dropped the user_role unique constraint.\r\n";
		foreach (getAffectedTableNames() as $tableName) {
			Schema::table($tableName, $object->dropOriginalFieldsAndRename($tableName));
		}
		echo "Finished renaming all new_ fields to the original names.\r\n";
		foreach (getAffectedTableNames() as $tableName) {
			Schema::table($tableName, $object->addForeignKeyConstraints($tableName));
		}
		Schema::table('user_role', function(Blueprint $table) {
			$table->unique(array('role_id', 'user_id'));
			$table->foreign('role_id')->references('id')->on('role');
		});
		echo "All should be complete.\r\n";
    }

    public function down()
    {
		foreach (getAffectedTableNames() as $tableName) {
			$object = new Test;
			$function = $object->undoChange($tableName);
			Schema::table($tableName, $function);
		}
    }
}
