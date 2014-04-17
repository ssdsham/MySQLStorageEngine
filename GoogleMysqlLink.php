<?php
error_reporting(E_ERROR | E_PARSE);
	//Header stuff/includes, etc
	require_once 'zend/Loader.php';
	//set_include_path(implode(PATH_SEPARATOR, array(realpath('/xampp/htdocs/zend'),get_include_path(),)));
	Zend_Loader::loadClass('Zend_Gdata');
	Zend_Loader::loadClass('Zend_Gdata_AuthSub');
	Zend_Loader::loadClass('Zend_Gdata_ClientLogin');
	Zend_Loader::loadClass('Zend_Gdata_Spreadsheets');
	
	//Make sure user entered MySQL information
	if(empty($_POST["mysqlHost"]) || empty($_POST["mysqlUsername"]) || empty($_POST["mysqlPassword"]) || empty($_POST["databaseName"]) || empty($_POST["tableName"]))
	{
		//If the required fields were not filled in, exit.
		echo "MySQL fields are required. <br>";
		exit;
	}
	else
	{
		//Import the mysql information
		$host = $_POST["mysqlHost"];
		$mysqlUser = $_POST["mysqlUsername"];
		$mysqlPassword = $_POST["mysqlPassword"];
		$databaseName = $_POST["databaseName"];
		$tableName =  $_POST["tableName"];

		//Connect to database
		$database = mysqli_connect($host,$mysqlUser, $mysqlPassword, $databaseName);
		if(mysqli_connect_errno()){
			echo "Connection to database ($databaseName) failed";
			exit;
		}
		if(empty($_POST["uploadOrDownload"])){
			//If the required fields were not filled in, exit.
			echo "You must indicate whether you are uploading to or downloading from Google Spreadsheets.<br>";
			exit;
		}
		//////////////////////////Handle uploading to Google from MySQL\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\
		elseif($_POST["uploadOrDownload"]=="uploadToGoogle")
		{
			//If the user didn't provide google credentials, exit.
			if(empty($_POST["googleAccount"]) || empty($_POST["googlePassword"]) || empty($_POST["spreadsheetName"]))
			{
				echo "Google account email, password, and the title of the spreadsheet document are required.<br>";
				exit;
			}
			//Load in user defined variables
			$googleUserName = $_POST["googleAccount"];
			$googlePassword = $_POST["googlePassword"];
			$spreadsheetName = $_POST["spreadsheetName"];
			
			//if the user specified a query
			if(empty($_POST["queryToUpload"])){
				$queryToUpload = "select * from $tableName";
			}
			else{
				$queryToUpload = $_POST["queryToUpload"];
			}
			
			///Code to clear the spreadsheet
			
			//function to get the google client
			function getClientLoginHttpClient($user, $pass)
			{
				$service = Zend_Gdata_Spreadsheets::AUTH_SERVICE_NAME;
				$client = Zend_Gdata_ClientLogin::getHttpClient($user, $pass, $service);
				return $client;
			}

			$client = getClientLoginHttpClient($googleUserName, $googlePassword);

			$spreadsheetService = new Zend_Gdata_Spreadsheets($client);

			// Get your spreadsheets feed
			$feed = $spreadsheetService->getSpreadsheetFeed();
			$spreadsheetFound = false;
			
			//Find the spreadsheet with the user given title
			foreach($feed->entries as $entry) {

				$title = $entry->title->text;
				if ($title == $spreadsheetName){
					$spreadsheetFound = true;
					$id = $entry->id;
				}
			}
			
			//If the spreadsheet wasn't found, exit.
			if($spreadsheetFound == false){
				echo "Spreadsheet by the title of $spreadsheetName was not found.<br>";
				exit;
			}
			
			// Get spreadsheet key
			$spreadsheetsKey = basename($id);   
			echo 'Your spreadsheet key is: ' . $spreadsheetsKey .'</br>';

			$query = new Zend_Gdata_Spreadsheets_DocumentQuery();
			$query->setSpreadsheetKey($spreadsheetsKey);
			$feed = $spreadsheetService->getWorksheetFeed($query);

			//Print worksheet ids
			foreach($feed->entries as $entry) {
				echo 'Your "'. $entry->title->text .'" worksheet ID is: ';
				$worksheetId = basename($entry->id);
				echo $worksheetId.'</br>';
			}

			// Get cell feed
			$query = new Zend_Gdata_Spreadsheets_CellQuery();
			$query->setSpreadsheetKey($spreadsheetsKey);
			$query->setWorksheetId($worksheetId);
			$cellFeed = $spreadsheetService->getCellFeed($query);

			//Clear the cells
			foreach($cellFeed as $cellEntry) {
				$row = $cellEntry->cell->getRow();
				$col = $cellEntry->cell->getColumn();
				$updatedCell = $spreadsheetService->updateCell($row,
				$col,'',$spreadsheetsKey,$worksheetId);
			}
			
			///Begin code to upload data
			if($result=mysqli_query($database, $queryToUpload)){
				
				//Get the metadata about the result set
				$columns = mysqli_fetch_fields($result);
				
				//Counter to determine number of columns
				$columnCount = 0;
				
				//Create column headings in first row of google spreadsheet
				foreach($columns as $column){
					//Fill in the current column of row 1 with the corresponding column name
					$columnCount++;
					$columnName = $column->name;
					$spreadsheetService->updateCell(1,$columnCount,$columnName,$spreadsheetsKey,$worksheetId);
				}
				
				//Start data at the second row
				$currentRow = 2;
				
				//Insert each row into the google docs, cell by cell
				while($row = mysqli_fetch_row($result)){
					
					//Add each value in the result array to its corresponding cell in the spreadsheet
					for($currentColumn = 1; $currentColumn <= $columnCount; $currentColumn++){
						$spreadsheetService->updateCell($currentRow,$currentColumn,$row[$currentColumn-1],$spreadsheetsKey,$worksheetId);
					}
					$currentRow++;
				}
				
			}
			else{
				echo "Error processing query.<br>";
				exit;
			}
			
		}
		//////////////////////////Handle downloading to MySQL from Google\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\
		elseif($_POST["uploadOrDownload"]=="downloadToMysql")
		{
			//If the required fields were not filled in, exit.
			if(empty($_POST["csvFeed"]))
			{
				echo "CSV feed for the google spreadsheet is required.<br>";
				exit;
			}
			//Load in user defined variables
			$feed = $_POST["csvFeed"];
			
			///Begin code to download data
			
			
			$keys = array();
			$newArray = array();
 
			// Function to convert CSV into associative array
			function csvToArray($file, $delimiter) { 
				if (($handle = fopen($file, 'r')) !== FALSE) { 
					$i = 0; 
					while (($lineArray = fgetcsv($handle, 4000, $delimiter, '"')) !== FALSE) { 
						for ($j = 0; $j < count($lineArray); $j++) { 
							$arr[$i][$j] = $lineArray[$j]; 
						}	 	
						$i++; 
					} 
					fclose($handle); 
				} 
				return $arr; 
			} 
 
			// Do it
			$data = csvToArray($feed, ',');
 
			// Set number of elements (minus 1 because we shift off the first row)
			$count = count($data) - 1;
 
			//Use first row for names  
			$labels = array_shift($data);  
			foreach ($labels as $label) {
				$keys[] = $label;
			}
 
			// Bring it all together
			for ($j = 0; $j < $count; $j++) {
				$d = array_combine($keys, $data[$j]);
				$newArray[$j] = $d;
			}
 
			// Print it out as JSON
			echo json_encode($newArray);
			echo "<br>";
			$page = ob_get_contents();
			ob_end_flush();
			$fp = fopen("output.json","w");
			fwrite($fp,$page);	
			fclose($fp);
	
			
			//mysqli_multi_query("use $databaseName", $database);
			
			//If the table we're importing already exists, drop it
			mysqli_multi_query($database,"drop table if exists $tableName");
			
			//Create column headings from labels
			$columnCount = 0;
			foreach($labels as $column) {
				$columnCount++;
				if(isset($columns)) $columns .= ', ';
				else $columns = "";
				$columns .= "$column varchar(250)";
			}
			
			//Create the table in the database
			$createQuery = "create table $tableName ($columns);";
			echo "Updated Table $tableName <br>";
			mysqli_multi_query($database, $createQuery);
			
			//Insert data from csv feed into newly created table
			foreach($data as $tuple){
				$insertStatement = "insert into $tableName values (";
				
				//The string to hold the values to be inserted
				$values = "";
				
				//Get each individual value out of the tuple to add it to the insert statement string
				foreach($tuple as $value){
					if($values!= "") $values .= ', ';
					$valueToInsert = mysqli_real_escape_string($database,$value);
					$values .= "'$valueToInsert'";
				}
				
				//Finish the statement and issue the command
				$insertStatement .= "$values);";
				mysqli_multi_query($database,$insertStatement);
				
				//echo "$insertStatement <br>";
			}
		}
		
		//Close the MySQL connection
		mysqli_close($database);
	}
	
?>