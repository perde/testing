<?php
require_once ('/home/hwarch/domains/hw-archive.com/library/spyc/spyc.php');
$rootDir = dirname(dirname ( dirname ( __FILE__ ) ));
define ( 'ROOT_DIR', $rootDir );
//$debug=true;

set_include_path ( get_include_path () . PATH_SEPARATOR . ROOT_DIR . '/library/' . PATH_SEPARATOR . ROOT_DIR . '/application/models/' );

define('PARSE_NewLine', "\r\n");
define('PARSE_NEW_RECORD', PARSE_NewLine . PARSE_NewLine . PARSE_NewLine);
define('PARSE_FIELD_DELIMITER', "  ");

mb_internal_encoding("UTF-8");

require_once 'Zend/Config/Ini.php';
$cfgArr = new Zend_Config_Ini ( ROOT_DIR . '/application/config.ini', 'live' );
$config = new Zend_Config ( $cfgArr->toArray () );

require_once('Zend/Db/Table/Abstract.php');

$db = Zend_Db::factory ( $config->db );
Zend_Db_Table_Abstract::setDefaultAdapter ( $db );

//array_walk_recursive($fieldsArr)
//$yaml = Spyc::YAMLDump($fields,4,60);


class xmlToOntoParser{

  public $outputType='mysql';//'file'
  public $transTable;
  public $xmlPath="/home/hwarch/domains/hw-archive.com/faust_import";
  public $outPath="/home/hwarch/domains/hw-archive.com/pl/kbase/onto";
  public $titleField='title';
  public $xmlFile="";
  public $objectType='';
  protected $xml;
  public $parsedRecords=array();
  public $pkName;
  public $sameTermsTogether=true;
  public $termPrefix;
  public $fieldsArr;
  protected $fpOut;
  protected $parserData;
  protected $termStrArr;
  public $listJunctor='-';//'='; '-' is used to generate key-value pairs. They can be converted to associative lists,
  //which are necessary to extract keys and other list operations
  //newTermsArr - array of newly generated terms, like travellingExhibitions
  protected $newTermsArr=array();
  public $debug=false;
  public $utf8=false;

  public function __construct( $xmlFile, $objectType, $pkName, $fieldsYaml,$output='mysql'){
    $this->fieldsArr=Spyc::YAMLLoadString($fieldsYaml);
    $this->xmlFile=$xmlFile;
    $this->objectType=$objectType;
    $this->outputType=$output;

    $this->utf8=$GLOBALS['utf8'];

    if ($this->debug){
      print "$objectType: Debug mode. Importing to table kb_trans2...\n";
    }
    $this->parserData=new parserEvents();

    if(!$this->xmlFile){
      die("Please set xmlFile\n");
    }

    if(!$this->objectType){
      die("Please set objectType\n");
    }

    if(!$pkName){
      $this->pkName[0]=$objectType;
    }elseif(!is_array($pkName)){
      $this->pkName[0]=$pkName;
    }else{
      $this->pkName=$pkName;
    }

    //$this->termPrefix=strtolower($objectType)."_";


    if(!is_array($this->xmlFile)){
      $this->xmlFileArr[]=$this->xmlFile;
    }else{
      $this->xmlFileArr=$this->xmlFile;
    }

    foreach($this->xmlFileArr as $xmlFile){
      $fileName=$this->xmlPath . '/' . $xmlFile;
      $this->xml[$xmlFile]=new XMLReader();
      $this->xml[$xmlFile]->open($fileName);
    }
    $now=date("Y-m-d_His");
    if ($this->outputType=='file'){
      $this->fpOut=fopen($this->outPath . '/' . $this->objectType . "_onto_$now.pl", 'w+');
    }else{
      if ($this->debug){
        require_once 'KbTrans2.php';
        $this->transTable=new KbTrans2();
      }else{
        require_once 'KbTrans.php';
        $this->transTable=new KbTrans();
      }
    }

  }

  public function parse(){

    //first we parse and make array of pointers to fields(multiline values), and records
    $parsedRecords=array();
    $count=array();
    $xmlIndex=-1;
    foreach($this->xml as $xml){
      $xmlIndex++;
      while($xml->read()){
        switch($xml->nodeType){
          case XMLReader::ELEMENT:
            switch($xml->localName){
              case 'Faust_Datenbank':
                $count['works']++;
                break;
              case 'FAUST_Objekt':
                $count['faustObjectNum']++;

                break;
              default:
                $bufName=$xml->localName;

                $count[$bufName]++;
                $xml->read();
                if($xml->hasValue){
                  $val=trim($xml->value);
                  if ($this->utf8){
                    $val=iconv("iso-8859-1", "utf-8", $val);
                  }
                  //$fObject[$count['faustObjectNum']][$bufName][]=$this->xml->value;
                  if($parsedRecords[$xmlIndex][$count['faustObjectNum']][$bufName]){
                    //then convert to an array
                    if(!is_array($parsedRecords[$xmlIndex][$count['faustObjectNum']][$bufName])){
                      $tmpVal=$parsedRecords[$xmlIndex][$count['faustObjectNum']][$bufName];
                      $parsedRecords[$xmlIndex][$count['faustObjectNum']][$bufName]=array($tmpVal);

                    }
                    array_push($parsedRecords[$xmlIndex][$count['faustObjectNum']][$bufName], $val);

                  }else{
                    $parsedRecords[$xmlIndex][$count['faustObjectNum']][$bufName]=$val;
                  }
                  //    array_push();


                }
            }
          case XMLReader::END_ELEMENT:
            switch($xml->localName){
              case 'Faust_Datenbank':
                $count['workEnd']++;
                break;
              case 'FAUST_Objekt':
                $count['faustObjectEnd']++;
                break;
              default:
                $count[$xml->localName . '_end']++;

            }
            break;

        }
      }
    }
    $this->parsedRecords=$parsedRecords;
    //print_r($parsedRecords);
    //die();
    ksort($count);
    $id=0;
    foreach($parsedRecords as $indx=>$recordsArr){

      $pkArr=array();
      foreach($recordsArr as $recNum=>$record){
        $data=array();
        $id++;
        //if ($recNum>1) break;
        //$pkTerm=$pkName."($recNum).";
        $curTermName='';
        //$this->outTerm($this->pkName, $recNum);


        //fputs($this->fpOut, "object({$this->pkName}, mb_strlen($termStr2));
        //print $recNum.", ";

        $this->termStrArr=array();
        //first we need to process pkName field (Primary key field)

        $pkVal=$record[$this->pkName[$indx]];

        $titleVal=$record[$this->titleField];
        if (is_array($titleVal)){
          $title=trim($titleVal[0]);
        }else{
          $title=trim($titleVal);
        }
        $title=htmlentities($title, ENT_QUOTES, 'UTF-8');
        if (!$title){
          die("Critical error:No title is set for {$this->pkName[$indx]}!\n It is abnormal!\n ");
        }

        if($pkVal){
          if (array_search($pkVal, $pkArr)===false){
            $pkArr[]=$pkVal;
          }else{
            print "Warning: $pkVal is duplicate in index {$this->pkName[$indx]}\n";
          }
          $now=date("Y-m-d H:i:s");
          if ($this->outputType=='file'){
            $str="%Andrea###$now###object###$this->objectType###$title".PARSE_NewLine;
            fputs($this->fpOut, $str);
            $str="object($this->objectType,'$pkVal','$title',[" . PARSE_NewLine;
            fputs($this->fpOut, $str);
            $str="id{$this->listJunctor}$id," . PARSE_NewLine;
            fputs($this->fpOut, $str);
            $str="typeOfObject{$this->listJunctor}'$this->objectType'," . PARSE_NewLine;
            fputs($this->fpOut, $str);
          }else{//mysql
            $data['term_head']='object';
            $data['term_arg1']=$this->objectType;
            $data['term_arg2']=htmlentities($pkVal,ENT_QUOTES, 'UTF-8');
            $data['term_arg3']=substr($title,0,254);
            $data['term_arity']=4;
            $data['term_list']='['.PARSE_NewLine."id{$this->listJunctor}$id," . PARSE_NewLine."typeOfObject{$this->listJunctor}'$this->objectType'," . PARSE_NewLine;
            $data['created_by']=1;//Archive/Andrea
            $data['created_on']=date("Y-m-d H:i:s");
            $data['created_ip']=($_SERVER['REMOTE_ADDR'])?($_SERVER['REMOTE_ADDR']):('localhost');
            $data['term_source']='arch';

          }


          //continue;
        }else{
          die("Critical error:No primary key value is set for {$this->pkName[$indx]}!\n It is abnormal!\n ");
        }

        foreach($record as $fld=>$valArr){
          $first=true;
         // print $fld."::"; print count($valArr); print"**************\n";


          if(is_array($valArr)){
            //print '!!!array!!!\n';
          	foreach($valArr as $l=>$val){
          		if($val){
          			//print $fld."\n";

          			$termStr=$this->genTerm($fld, $val, $curTermName, $first);
          			//print "==================term: $termStr==============\n";
          			if($termStr){
          				$this->termStrArr[]=$curTermName . $this->listJunctor . $termStr;
          			}

          			if ($fld=='Published_Year' && $this->objectType=='hwg'){
          				$termStr=$this->genTerm('year', $valArr, $curTermName, $first);
          				if($termStr){
          					$this->termStrArr[]=$curTermName . $this->listJunctor . $termStr;
          				}
          			}
          		}
            }

          }elseif(!empty($valArr)){
            $termStr=$this->genTerm($fld, $valArr, $curTermName, $first);
            if($termStr){
              $this->termStrArr[]=$curTermName . $this->listJunctor . $termStr;
            }
            //duplicate publishedYear field into year for hwg works, done for consistancy
            if ($fld=='Published_Year' && $this->objectType=='hwg'){
            	
            	$termStr=$this->genTerm('year', $valArr, $curTermName, $first);
            	if($termStr){
                  $this->termStrArr[]=$curTermName . $this->listJunctor . $termStr;
            	}

            }
          }
        }

        if($this->termStrArr){

          $termStr=implode("," . PARSE_NewLine, $this->termStrArr);

          if ($this->outputType=='file'){
            fputs($this->fpOut, $termStr);
          }else{
            $data['term_list'].=$termStr;
          }
        }

        if ($this->outputType=='file'){
          $str=PARSE_NewLine . "])." . PARSE_NewLine.PARSE_NewLine;
          fputs($this->fpOut, $str);
        }else{
          $str=PARSE_NewLine . "]";
          $data['term_list'].=$str;
        }
        //print_r($data);
        $res=$this->transTable->update(array('term_active'=>0,'term_updated'=>$now),"term_head='{$data['term_head']}' && term_arity='{$data['term_arity']}' && term_arg1='{$data['term_arg1']}' && term_arg2='{$data['term_arg2']}' && term_active=1");
        
        //Reemplazar html tag <\span> por </span> - Modificado por GC
        $data['term_list'] = str_replace("&lt;\span&gt", "&lt;/span&gt", $data['term_list']);
        
        echo $data['term_arg1']. "-" .$data['term_arg2'] . "\n";

        $this->transTable->insert($data);
      }
    }
    //print_r($this->newTermsArr);
    if ($this->outputType=='file'){
      array_walk_recursive($this->newTermsArr, 'output', $this->fpOut);
      fclose($this->fpOut);
    }else{
      array_walk_recursive($this->newTermsArr, 'myOutput', $this->transTable);
    }

    foreach($this->xml as $xml){
      $xml->close();
    }

  }

  protected function genTerm( $fld, $val, &$termName, &$first){
    // $fieldsArr;
    static $stack=array();
    $termName='';
    $fldProps=$this->fieldsArr[$fld];
    $val=tryToInt($val);
    if(is_string($val)){
      $val=htmlentities($val, ENT_QUOTES, 'UTF-8');
    }

    if(is_array($fldProps)){

      if(method_exists($this, $fldProps['term'])){

        //print "{$fldProps['term']}($val,$currentKeyId,$index)\n";
        $termName=strToCamel($fld);

        $termStr=$this->$fldProps['term']($val, $termName);

        //if($termName == 'literatureExhibitionCatalogues'){
        //  print $termName . "=" . $termStr . "\n";
        //  exit();
        //}
        //print_r($this->newTermsArr);


        if($termName){
          if(is_string($termStr)){
            //check if it is not a vector
            if(mb_substr($termStr, 0, 1) != '['){
              $termStr="'$termStr'";
            }
          }
        }

      }else{
        $curSfx='';
        if($fldProps['alternate']){
          if($first){
            $stack=$fldProps['alternate'];
            $first=false;
          }
          $curSfx=array_shift($stack);
        }
        if($fldProps['term']){
          $termName=$fldProps['term'] . $curSfx;
        }else{
          $termName=strToCamel($fld);
        }

        if($termName){
          if(is_integer($val)){
            $termStr="$val";
          }else{
            $termStr="'$val'";
          }
        }
      }

    }else{
      $termName=strToCamel($fld);
      if($termName){
        if(is_integer($val)){
          $termStr="$val";
        }else{
          $termStr="'$val'";
        }
      }
    }

    //if (stristr($termName,'exhib')){
    // print $termStr;
    // die();
    // }
    return $termStr;

    //print
  }

  protected function outTerm( $propName, $value){
    //$$this->sameTermsTogether, $kbArr, $fpOut, $termPrefix;
    $termStr="$propName='$value'" . PARSE_NewLine;
    fputs($this->fpOut, $termStr);
  }

  protected function coordinator( $str){
    $str=trim($str);
    if(!$str)
    return false;
    /// ??? do we need ids ????, NO!!!!


    $termStr="object('person',['name'{$this->listJunctor}'$str']).";
    //print $termStr;
    if(empty($this->newTermsArr['person']) || array_search($termStr, $this->newTermsArr['person']) === false){
      $this->newTermsArr['person'][]=$termStr;
    }

    $coordStr="[person='$str']";
    return $coordStr;
  }

  protected function litMonographs( $str, $curId){
    //Literature: Monographs
    //parse in form:
    //author,name, year, pointers
    //author is first field
    //year is year of issue
    //pointers are all page nums etc
    //name - rest of fields including name of catalog, sub.suthors, cities etc
    $str=trim($str);
    if(!$str)
    return false;
    $tmpArr=explode(',', $str);
    array_walk($tmpArr, 'hw_trim');

    $author=$tmpArr[0];
    $usedIndex[]=0;

    $authId=$this->parserData->addAuthor($author, $new);
    if($new){
      $authStr="$authId,'$author'";
      $this->outTerm('Author', $authStr);

    }

    $len=sizeof($tmpArr);
    for($k=1; $k < $len; $k++){
      $el=$tmpArr[$k];
      if(preg_match("@^[\d]{4}$@", $el)){

        $monoYear=$el;
        $usedIndex[]=$k;
      }
      if(preg_match("@p. |pl. @i", $el)){
        $pointerArr[]=$el;
        $usedIndex[]=$k;
      }
    }
    if($pointerArr){
      $monoPointers=implode(',', $pointerArr);
    }

    //gather all not used elements
    for($k=1; $k < $len; $k++){
      if(!in_array($k, $usedIndex)){
        $remArr[]=$tmpArr[$k];
      }
    }

    if($remArr){
      $monoName=implode(',', $remArr);
    }

    if(!$monoYear){
      $monoYear="''";
    }

    $monoStr.="$curId,$authId,'$monoName',$monoYear,'$monoPointers'";
    $this->outTerm("LiteratureMonographs", $monoStr);

  }

  /**
   * General function to add and object and property in such a way that it will be easy to make links between them and make queries.
   *
   * @param $objName - is name of the field/property of the object. oneManExhibitions, coordinator, literatureExhibitionCatalogues etc
   * @param $objProps - array of properties of the field and their values. For each property function will create object...
   * @return unknown_type
   */
  protected function addObject( $objName, $objProps){
    //array_walk($objProps, 'addSingleQuotes');


    /*
     *
     if (empty($objProps['archiveText'])){
     die("please set archiveText field in $this->objectType > $objName parssing function");
     }
     */

    $objName=wrapInQuotes($objName);

    //create object for each property
    $propStr='';
    foreach($objProps as $prop=>$value){
      $prop=wrapInQuotes($prop);
      $value=wrapInQuotes($value);

      $termStr="object($prop,[name{$this->listJunctor}$value,sourceField{$this->listJunctor}$objName, sourceObjectType{$this->listJunctor}'$this->objectType'])"; //objType is APA, PAINT, etc
      if(!$this->newTermsArr[$prop] || array_search($termStr, $this->newTermsArr[$prop]) === false){
        $this->newTermsArr[$prop][]=$termStr;
      }
      if($value != "" && $value != "''"){
        if($propStr){
          $propStr.=", $prop{$this->listJunctor}$value";
        }else{
          $propStr="$prop{$this->listJunctor}$value";
        }
      }
    }
    $propTermStr="[$propStr]";
    return $propTermStr;
  }

  protected function litExhibCatalogs( $str, $objName){
    //print ".";
    //Literature: Monographs
    //parse in form:
    //author,name, year, pointers
    //author is first field
    //year is year of issue
    //pointers are all page nums etc
    //name - rest of fields including name of catalog, sub.authors, cities etc
    $str=trim($str);
    if(!$str)
    return false;
    $tmpArr=explode(',', $str);
    array_walk($tmpArr, 'hw_trim');

    $usedIndex[]=0;

    $len=sizeof($tmpArr);
    for($k=1; $k < $len; $k++){
      $el=$tmpArr[$k];
      if(preg_match("@^[\d]{4}$@", $el)){
        $monoYear=$el;
        $usedIndex[]=$k;
      }elseif(preg_match("@^(?<year>[\d]{4})\s{1,2}(?<colored>\(c\))$@", $el, $matches)){
        $monoYear=$matches['year'];
        $color=$matches['colored'];
        $usedIndex[]=$k;
      }
      if(preg_match("@p. |pl. @i", $el)){
        $pointerArr[]=$el;
        $usedIndex[]=$k;
      }
    }
    if($pointerArr){
      $monoPointers=implode(',', $pointerArr);
    }

    //gather all not used elements
    for($k=1; $k < $len; $k++){
      if(!in_array($k, $usedIndex)){
        $remArr[]=$tmpArr[$k];
      }
    }

    $sz=sizeof($remArr);
    //if ($sz>1){
    $city=$remArr[$sz - 1];
    //$usedIndex[]=$sz - 1;
    unset($remArr[$sz - 1]);

    //}
    if($remArr){
      $monoName=implode(',', $remArr);
    }

    if(!$monoYear){
      $monoYear="''";
    }

    //$monoStr="$curId,$exhibCatalogId,'$monoName',$monoYear,'$monoPointers'";
    //$this->outTerm("LiteratureExhibitionCatalogs", $monoStr);


    $objProps=array('exhibition'=>$monoName,'year'=>$monoYear,'pointer'=>$monoPointers,'color'=>$color);
    if($city){
      $objProps['city']=$city;
    }

    //print_r($objProps);


    $newObjectStr=$this->addObject($objName, $objProps);

    //print_r($this->newTermsArr);
    //exit("ObjProps...");
    return $newObjectStr;

  }

  protected function groupExhibitions( $str, $objName, $curId=0, $index=0){
    //global $fpOut, $parserData, $parsedRecords;
    $str=trim($str);
    if(!$str)
    return false;
    //@todo - add travelling exhibition parser


    //check how many comma separated parts are in sentence
    //
    $tmpArr=explode(",", $str);

    $cnt=count($tmpArr);
    array_walk($tmpArr, 'hw_trim');

    $yearRegex="@(?P<year1>[\d]{2,4})?[-\/]?(?P<year2>[\d]{2,4})?[\s^\r\n]{1,4}(?P<portfolio>[\w\(\)]{2,4})?@i";
    switch($cnt){
      case 1:
        //print "Gallery with no , - error\n";
        $exhibitionName=$tmpArr[0];
        //exit($str);
        break;
      case 2:
        $exhibitionName=$tmpArr[0];
        preg_match($yearRegex, $tmpArr[1], $yrMatch);
        $year1=$yrMatch['year1'];
        $year2=$yrMatch['year2'];
        if($yrMatch['portfolio']){
          $portfolio='portfolio';
        }
        break;
      case 3:
        $exhibitionName=$tmpArr[0];
        $galleryCity=$tmpArr[1];
        preg_match($yearRegex, $tmpArr[2], $yrMatch);
        $year1=$yrMatch['year1'];
        $year2=$yrMatch['year2'];
        if($yrMatch['portfolio']){
          $portfolio='portfolio';
        }
        break;
      case 4:
        $exhibitionName=$tmpArr[0];
        $galleryName=$tmpArr[1];
        $galleryCity=$tmpArr[2];
        preg_match($yearRegex, $tmpArr[3], $yrMatch);
        $year1=$yrMatch['year1'];
        $year2=$yrMatch['year2'];
        if($yrMatch['portfolio']){
          $portfolio='portfolio';
        }
      case 5:
        $exhibitionName=$tmpArr[0];
        $exhibitionName2=$tmpArr[1];
        $galleryName=$tmpArr[2];
        $galleryCity=$tmpArr[3];
        preg_match($yearRegex, $tmpArr[4], $yrMatch);
        $year1=$yrMatch['year1'];
        $year2=$yrMatch['year2'];
        if($yrMatch['portfolio']){
          $portfolio='portfolio';
        }

        break;
      default:
        print __FUNCTION__ . "\n";
        print_r($this->parsedRecords[$curId]['Work No']);
        print_r($this->parsedRecords[$curId]['Group exhibitions']);
        print $str . "\n";
        exit("record # $curId\n Line: $index\n. Number of data fields in gallery is $cnt. Please write the code for this event");

        //preg_match("@^(?P<galleryName>[^,\r\n]*), ?(?P<galleryCity>[^,\r\n]*)?, ?(?P<year1>[\d]{0,4})?[-\/]?(?P<year2>[\d]{0,4})?[\s^\r\n]{1,4}(?P<portfolio>[/(pf/)]{2,4})?$@i",$str,$match);
    }

    $year1=hwConvYear($year1);
    $year2=hwConvYear($year2);
    if(!$year2){
      $year2=$year1;
    }
    if(!$year1){
      $year1=0;
    }
    if(empty($year1)){
      $year1=0;
      $year2=0;
    }
    $objProps=array('exhibitionName'=>$exhibitionName,'exhibitionName2'=>$exhibitionName2,'galleryName'=>$galleryName,'galleryCity'=>$galleryCity,'year1'=>$year1,'year2'=>$year2,'portfolio'=>$portfolio);
    //archiveText property is for storage of the text which came from Archive(Source of information)


    $termStr=$this->addObject($objName, $objProps);

    return $termStr;

  }

  protected function oneManExhibitions( $str){
    //global $fpOut, $parserData, $parsedRecords;
    $str=trim($str);
    if(!$str)
    return false;
    //@todo - add travelling exhibition parser
    //:
    //*
    //fputs($fpOut,$str."\n");
    $pos=mb_strpos($str, ":");
    $len=mb_strlen($str) - 1;
    $foundStr=mb_stristr($str, 'Travelling');
    $foundTravelling=mb_stristr($str, 'exhibition:');
    //print $pos.'-'.$len.'-'.$foundStr."\n";


    if((($pos == $len) && $foundStr) || $foundTravelling){
      //parse group of exhibition header
      //
      //print $str."\n";
      $match=array();
      preg_match("@([^,\r\n\d]*) ?([\d]{4})[/-]?([\d]{0,4})@", $str, $match);
      if(!$match){
        $travellingExhibName=mb_substr($str, 0, $len);
      }else{
        $grId=$this->parserData->incCurExhibGroup();
        $travellingExhibName=$match[1];
        $year1=hwConvYear($match[2]);
        $year2=hwConvYear($match[3]);
        if(!$year2){
          $year2=$year1;
        }
        if(!$year1){
          $year1=0;
        }
        if(empty($year1)){
          $year1=0;
          $year2=0;
        }
      }
      $termStr="object('travellingExhibition',['name'{$this->listJunctor}'$travellingExhibName','year_from'{$this->listJunctor}'$year1', year_to{$this->listJunctor}'$year2']).";
      //print $termStr;
      if(empty($this->newTermsArr['travellingExhibition'][$travellingExhibName]) || array_search($termStr, $this->newTermsArr['travellingExhibition'][$travellingExhibName]) === false){
        $this->newTermsArr['travellingExhibition'][$travellingExhibName][]=$termStr;
      }

      //print_r($this->newTermsArr);
      $this->currentTravellingExhibition=$travellingExhibName;
      //$this->outTerm("GroupGallery", $galGroupTerm);
    }elseif($str == '*'){
      //$parserData->setCurrentExbGroupId(0);
      $this->currentTravellingExhibition='';
    }else{

      if(mb_stristr($str, "Galleria La Medusa")){
        //$ind=1;
      }
      //check how many comma separated parts are in sentence
      //
      //    if (mb_strpos(strrev($str),strrev("(a, b)"))===0){
      //      $str=str_replace("(a, b)","",$str);
      //      $exhibYearAB="a,b";
      //    }
      $abFindRex="@\(([ab, ]+)\)@i";
      preg_match($abFindRex, $str, $match);
      if($match[0]){
        $str=str_replace($match[0], "", $str);
        $exhibAB=$match[0];
      }

      $tmpArr=explode(",", $str);
      $cnt=count($tmpArr);
      $yearRegex="@(?P<year1>[\d]{2,4})?[-\/]?(?P<year2>[\d]{2,4})?[\s^\r\n]{1,4}(?P<portfolio>[\w\(\)]{2,4})?@i";
      $parsed=false;
      switch($cnt){
        case 1:
          //print "Gallery with no , - error\n";
          $galleryName=trim($tmpArr[0]);
          //exit($str);
          break;
        case 2:
          $galleryName=trim($tmpArr[0]);
          preg_match($yearRegex, trim($tmpArr[1]), $yrMatch);
          $year1=$yrMatch['year1'];
          $year2=$yrMatch['year2'];
          if($yrMatch['portfolio']){
            $portfolio='portfolio';
          }
          break;
        case 3:
          $galleryName=trim($tmpArr[0]);
          $galleryCity=trim($tmpArr[1]);
          preg_match($yearRegex, trim($tmpArr[2]), $yrMatch);
          $year1=$yrMatch['year1'];
          $year2=$yrMatch['year2'];
          if($yrMatch['portfolio']){
            $portfolio='portfolio';
          }
          break;
        case 4:
          $galleryName=trim($tmpArr[0]);
          $galleryOrg=trim($tmpArr[1]);
          $galleryCity=trim($tmpArr[2]);
          preg_match($yearRegex, trim($tmpArr[3]), $yrMatch);
          $year1=$yrMatch['year1'];
          $year2=$yrMatch['year2'];
          if($yrMatch['portfolio']){
            $portfolio='portfolio';
          }
          break;
        default:
          $parsed=false;
          //print __FUNCTION__ . "\n";
          //print_r($this->parsedRecords[$curId]['Work No']);
          //print_r($this->parsedRecords[$curId]['One-man exhibitions']);
          //print $str . "\n";
          //exit("record # $curId\n Line $index\n Number of data fields in gallery is $cnt. Please write the code for this event");


          //preg_match("@^(?P<galleryName>[^,\r\n]*), ?(?P<galleryCity>[^,\r\n]*)?, ?(?P<year1>[\d]{0,4})?[-\/]?(?P<year2>[\d]{0,4})?[\s^\r\n]{1,4}(?P<portfolio>[/(pf/)]{2,4})?$@i",$str,$match);
      }

      //$parserData->incGalleryCount();
      //$galId=$this->parserData->addGallery($galleryName, $new);
      //print $galId.":$galleryName\n";
      if($parsed){
        $galleryTermStr="object('gallery',[name{$this->listJunctor}'$galleryName', organisation{$this->listJunctor}'$galleryOrg', city{$this->listJunctor}'$galleryCity']).";
        if(empty($this->newTermsArr['gallery']) || array_search($galleryTermStr, $this->newTermsArr['gallery']) === false){
          $this->newTermsArr['gallery'][]=$galleryTermStr;
        }

        $year1=hwConvYear($year1);
        $year2=hwConvYear($year2);

        //$year1Int=tryToInt($year1);
        //$year2Int=tryToInt($year2);
        if(empty($year2)){
          $year2=$year1;
        }
        if(empty($year1)){
          $year1=0;
          $year2=0;
        }
        $resTermStr="[gallery{$this->listJunctor}'$galleryName', organisation{$this->listJunctor}'$galleryOrg', city{$this->listJunctor}'$galleryCity', travellingExhibition{$this->listJunctor}'{$this->currentTravellingExhibition}', yearFrom{$this->listJunctor}'$year1', yearTo{$this->listJunctor}'$year2',portfolio{$this->listJunctor}'$portfolio',catalog{$this->listJunctor}'$exhibAB']";
        //print $resTerm."\n";
        return $resTermStr;
        //$parserData->addOneManExhibitions($oneManExhib);
      }else{
        return $str;
      }
    }
  }

}

class parserEvents{
  protected $_curExbGroupId=0;
  protected $_galleryCount=0;
  protected $_galleryGroupCnt=0;
  protected $_galleryArray=array();
  protected $_authorCount=0;
  protected $_authorArr=array();
  protected $_galleryCatalogCount=0;
  protected $_galleryCatalog=array();

  public function setCurrentExbGroupId( $val){
    $this->_curExbGroupId=$val;
  }

  public function incCurExhibGroup(){
    return $this->_curExbGroupId=$this->_curExbGroupId + 1;
  }

  public function getCurrentExbGroupId(){
    return $this->_curExbGroupId;
  }

  public function incGalleryCount(){
    return $this->_galleryCount=$this->_galleryCount + 1;
  }

  public function addGallery( $galName, &$new){
    if($this->_galleryArray[$galName]){
      $new=false;
      return $this->_galleryArray[$galName];
    }else{
      $cnt=$this->incGalleryCount();
      $this->_galleryArray[$galName]=$cnt;
      $new=true;
      return $cnt;
    }

  }

  public function getGalleryCount(){
    return $this->_galleryCount;
  }

  public function incAuthorCount(){
    return $this->_authorCount=$this->_authorCount + 1;
  }

  public function getAuthorCount(){
    return $this->_authorCount;
  }

  public function addAuthor( $auth, &$new){
    if($this->_authorArr[$auth]){
      $new=false;
      return $this->_authorArr[$auth];
    }else{
      $new=true;
      $cnt=$this->incAuthorCount();
      $this->_authorArr[$auth]=$cnt;
      return $cnt;
    }

  }

  public function incExhibCatalogCount(){
    return $this->_exhibCatalogCount=$this->_exhibCatalogCount + 1;
  }

  public function getExhibCatalogCount(){
    return $this->_exhibCatalogCount;
  }

  public function addExhibCatalog( $exhibCatal, &$new){
    if($this->_exhibCatalog[$exhibCatal]){
      $new=false;
      return $this->_exhibCatalog[$exhibCatal];
    }else{
      $new=true;
      $cnt=$this->incExhibCatalogCount();
      $this->_exhibCatalog[$exhibCatal]=$cnt;
      return $cnt;
    }
  }

  public function incCoordinatorCount(){
    return $this->_coordinatorCount=$this->_coordinatorCount + 1;
  }

  public function getCoordinatorCount(){
    return $this->_coordinatorCount;
  }

  public function addCoordinator( $auth, &$new){
    if($this->_coordinatorArr[$auth]){
      $new=false;
      return $this->_coordinatorArr[$auth];
    }else{
      $new=true;
      $cnt=$this->incCoordinatorCount();
      $this->_coordinatorArr[$auth]=$cnt;
      return $cnt;
    }
  }
}
/**
 * warp string in single quotes. If they already exist, first remove them(it is faster to remove then search)
 * if $str is integer after single quotes are removed then do not wrap it. Integer does not need quotes in Pl.
 * @param $str
 * @return unknown_type
 */
function wrapInQuotes( $str){
  $str2=trim($str, "'");
  //print $str2."<br>";
  $str3=(int)$str2;
  //print $str3."<br>";
  if(strcmp($str3, $str2) === 0){
    return $str2;
  }elseif(is_string($str2) || empty($str2)){
    $str2="'" . $str2 . "'";
  }
  return $str2;
}

function addSingleQuotes( &$item, $key){
  if(is_string($item) || empty($item)){
    $item="'$item'";
  }
}

function tryToInt( $val){
  if(is_string($val)){
    $valInt=(int)$val;
    $str=(string)$valInt;
    if($val == $str){
      return $valInt;
    }else{
      return $val;
    }
  }else{
    return $val;
  }
}

function hwConvYear( $year){
  if($year){
    if(strlen($year) < 3){
      if((int)$year > 30){
        $year=1900 + $year;
      }else{
        $year=2000 + $year;
      }
    }

  }
  return $year;
}

function output( $item, $key, $fp){
  //print $item."\n";
  $item=rtrim($item, ".");
  fputs($fp, $item . ".\n");
}
function myOutput($item, $key,HwDbTable $table){
  //$item=rtrim($item, ".");
  require_once 'Hw/MyTrans.php';
  $arr=Hw_MyTrans::parseTerm($item);
  $data['term_head']=$arr['functor'];
  $data['term_arity']=$arr['arity'];
  $data['term_arg1']=$arr['arg1'];
  $data['term_arg2']=$arr['arg2'];
  $data['term_arg3']=$arr['arg3'];
  $data['term_arg4']=$arr['arg4'];
  $data['term_list']=$arr['list'];
  $data['created_by']=1;//Archive/Andrea
  $data['created_on']=date("Y-m-d H:i:s");
  $data['created_ip']=($_SERVER['REMOTE_ADDR'])?($_SERVER['REMOTE_ADDR']):('localhost');
  $data['term_source']='arch';
  $res=$table->update(array('term_active'=>0),"term_head='{$data['term_head']}' && term_arity='{$data['term_arity']}' && term_arg1='{$data['term_arg1']}' && term_arg2='{$data['term_arg2']}' && term_active=1");
  //print "\nres:".$res."\n";
  //print_r($data);
  $table->insert($data);

}

function upstr( &$str){
  $str=strtolower($str);
  $str=ucfirst($str);
}
function strToCamel( $str, $firstUpper=false){
  $arr=preg_split("@[\_\-\s]+@", $str);
  array_walk($arr, 'upstr');
  $newStr=implode('', $arr);
  if(!$firstUpper){
    $fletter=mb_substr($newStr, 0, 1);
    $fletter=strtolower($fletter);
    $newStr=substr_replace($newStr, $fletter, 0, 1);
  }
  return $newStr;
}

function hw_trim( &$str){
  $str=trim($str);
}