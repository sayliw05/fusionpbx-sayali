
--connect to the database
	function database_handle(t)
		if (t == "system") then
			freeswitch.consoleLog("notice", "[db_handle] Connecting to SYSTEM database: " .. tostring(database.system) .. "\n");
			return freeswitch.Dbh(database.system);
		elseif (t == "switch") then
			freeswitch.consoleLog("notice", "[db_handle] Connecting to SWITCH database: " .. tostring(database.switch) .. "\n");
			return freeswitch.Dbh(database.switch);
		end
	end
