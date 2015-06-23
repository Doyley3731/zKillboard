<?php

for ($i = 0; $i < 20; $i++)
{
	$pid = pcntl_fork();
	if ($pid == -1) exit();
	if ($pid == 0) break;
}
if ($pid != 0) exit();

require_once "../init.php";

$timer = new Timer();
$tqApiChars = new RedisTimeQueue("tqApiChars", 3600);

$count = 0;
while ($timer->stop() <= 59000)
{
	$row = $tqApiChars->next();
	if ($row !== null)
	{
		$charID = $row["characterID"];
		$keyID = $row["keyID"];
		$vCode = $row["vCode"];
		$type = $row["type"];
		$charCorp = $type == "Corporation" ? "corp" : "char";
		$killsAdded = 0;

		\Pheal\Core\Config::getInstance()->http_method = "curl";
		\Pheal\Core\Config::getInstance()->http_user_agent = "API Fetcher for http://$baseAddr";
		\Pheal\Core\Config::getInstance()->http_post = false;
		\Pheal\Core\Config::getInstance()->http_keepalive = 30; // KeepAliveTimeout in seconds
		\Pheal\Core\Config::getInstance()->http_timeout = 60;
		\Pheal\Core\Config::getInstance()->api_customkeys = true;
		\Pheal\Core\Config::getInstance()->api_base = "https://api.eveonline.com/";
		$pheal = new \Pheal\Pheal($keyID, $vCode);

		$charCorp = ($type == "Corporation" ? 'corp' : 'char');
		$pheal->scope = $charCorp;
		$result = null;

		$params = array();
		$params['characterID'] = $charID;
		$result = null;

		try 
		{
			$result = $pheal->KillMails($params);
		} catch (Exception $ex)
		{
			$errorCode = $ex->getCode();
			if ($errorCode == 904) { Util::out("(apiConsumer) 904'ed..."); exit(); }
			if ($errorCode == 28)
			{
				Util::out("(apiConsumer) API Server timeout");
				exit();
			}
			// Error code 0: Scotty is up to his shenanigans again (aka server issue)
			// Error code 221: server randomly throwing an illegal access error even though this is a legit call
			if ($errorCode != 0 && $errorCode != 221) $tqApiChars->remove($row);
			continue;
		}
		$newMaxKillID = 0;
		foreach ($result->kills as $kill)
		{
			$killID = (int) $kill->killID;

			$newMaxKillID = (int) max($newMaxKillID, $killID);

			$json = json_encode($kill->toArray());
			$killmail = json_decode($json, true);
			$killmail["killID"] = (int) $killID; // make sure killID is an int;
			if (!$mdb->exists("crestmails", ['killID' => $killID]) && !$mdb->exists("apimails", ['killID' => $killID])) $mdb->insertUpdate("apimails", $killmail);

			$victim = $killmail["victim"];
			$victimID = $victim["characterID"] == 0 ? "None" : $victim["characterID"];

			$attackers = $killmail["attackers"];
			$attacker = null;
			if ($attackers != null) foreach($attackers as $att)
			{
				if ($att["finalBlow"] != 0) $attacker = $att;
			}
			if ($attacker == null) $attacker = $attackers[0];
			$attackerID = $attacker["characterID"] == 0 ? "None" : $attacker["characterID"];

			$shipTypeID = $victim["shipTypeID"];

			$dttm = (strtotime($killmail["killTime"]) * 10000000) + 116444736000000000;

			$string = "$victimID$attackerID$shipTypeID$dttm";

			$hash = sha1($string);

			$killInsert = ['killID' => (int) $killID, 'hash' => $hash];
			$exists = $mdb->exists("crestmails", $killInsert);
			if (!$exists) $mdb->getCollection("crestmails")->save(['killID' => (int) $killID, 'hash' => $hash, 'processed' => false, 'source' => 'api', 'added' => $mdb->now()]);
			if (!$exists) $killsAdded++;
			if (!$exists && $debug) Util::out("Added $killID from API");
		}

		// helpful info for output if needed
		$info = $mdb->findDoc("information", ['type' => 'characterID', 'id' => $charID], [], [ 'name' => 1, 'corporationID' => 1]);
		$corpInfo = $mdb->findDoc("information", ['type' => 'corporationID', 'id' => @$info["corporationID"]], [], [ 'name' => 1]);

		$apiVerifiedSet = new RedisTtlSortedSet("ttlss:apiVerified", 86400);
		$apiVerifiedSet->add(time(), ($type == "Corporation" ? @$info["corporationID"] : $charID));
		if ($newMaxKillID == 0) $tqApiChars->setTime($row, time() + rand(72000, 86400));

		// If we got new kills tell the log about it
		if ($killsAdded > 0)
		{
			if ($type == "Corporation") $name = "corp " . @$corpInfo["name"];
			else $name = "char " . @$info["name"];
			while (strlen("$killsAdded") < 3) $killsAdded = " " . $killsAdded;
			Util::out("$killsAdded kills added by $name");
		}
	}
	sleep(1);
}
