<?php

class Sejmometr extends AppModel
{

    public $useTable = false;

    public function autorzy_projektow()
    {
		
		App::import('model','DB');
		$this->DB = new DB();
		
        $data = $this->DB->selectAssocs("SELECT `s_projekty_podmioty`.`podmiot_id` as 'podmiot_id', `s_podmioty`.`legislacja_typ_id` as 'typ_id', `s_podmioty`.`nazwa`, COUNT(*) as 'count' 
        	FROM `s_projekty_podmioty` 
			JOIN `s_projekty` 
			ON `s_projekty_podmioty`.`projekt_id` = `s_projekty`.`id` 
			JOIN `s_podmioty` 
			ON `s_projekty_podmioty`.`podmiot_id` = `s_podmioty`.`id`
			WHERE `s_projekty`.`akcept` = '1' 
			AND `s_projekty`.`typ_id` = 1
			GROUP BY `s_projekty_podmioty`.`podmiot_id` 
			ORDER BY COUNT(*) DESC
			LIMIT 100");
		
		return $data;

    }

} 