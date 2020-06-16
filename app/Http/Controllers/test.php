<?
error_reporting(E_ALL & ~E_NOTICE);

date_default_timezone_set('Europe/Paris' );

$rClasses = Array('MicroTimer',
                  'db',
                  'registry',
                  'Logger',
                  'Main');

  foreach ( $rClasses as $rClass )
  {
    if ( file_exists( ''.$rClass.'.class.php' ) && is_readable( ''.$rClass.'.class.php' ) )
    {
      require ''.$rClass.'.class.php';
    }
  }

session_start();

require_once 'cfg.php';

function exceptionDie(Exception $e){
    if(DEBUG) {
        echo $e->getMessage().'<br />';
        echo 'Plik: '.$e->getFile().'<br />';
        echo 'Linia: '.$e->getLine().'<br />';
        echo 'Trace: '.$e->getTraceAsString().'<br />';
        echo 'Kod: '.$e->getCode().'<br />';
        die();
    }
    else {
        die('Przepraszamy, wystapil blad!');
    }
}

function microtime_float()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}


function connectMSSQL()
{

	$connectionInfo = array( "Database"=>registry::get('msdbName'), "UID"=>registry::get('msdbUser'), "PWD"=>registry::get('msdbPassword') );

	/* Connect using Windows Authentication. */
	$connectionInfo = array( "Database"=>registry::get('msdbName') );

	$conn = sqlsrv_connect( registry::get('msdbHost'), $connectionInfo);
	if( $conn === false )
	{
	     echo "Unable to connect.</br>";
	     die( print_r( sqlsrv_errors(), true));
	}
	sqlsrv_configure ( 'WarningsReturnAsErrors', 0 );
	return $conn;
}

function sqlsrv_query_wrap($subiekt, $tsql, $procedure = false){
	global $logger;

	$params = array();
	if($procedure == true)
		$options = array();
	else
		$options =  array( "Scrollable" => SQLSRV_CURSOR_KEYSET );

	$stmt = sqlsrv_query( $subiekt, $tsql, $params, $options );

	if( $stmt === false ) {
		$logger->error(print_r( sqlsrv_errors(), true).'QUERY: '.$tsql,1);
  		echo print_r( sqlsrv_errors(), true).'QUERY: '.$tsql;
  	}
	return $stmt;
}

try
{
	$mysql = db::getInstance();
	$logger = Logger::getInstance();



	$subiekt = connectMSSQL();


    $time_start = microtime_float();

	//pobierz zamowienia z bazy
	$orders = $mysql->query("SELECT * FROM ivon_orders WHERE zk_subiekt = '0' AND id >= 21873 ORDER BY date asc LIMIT 10 ");
    $orders = $orders->toArray();

	foreach($orders as $order){

		//die('zrobic join na order_delivery');
		$pos = $mysql->query("SELECT * FROM ivon_order_positions WHERE orderId = ".$order->id);
		$pos = $pos->toArray();

//czy sa towary o takim symbolu
		foreach($pos as &$p){
			$p->symbol = iconv('UTF-8', 'Windows-1250', $p->symbol);
			$tsql = "SELECT count(tw_Id) as ile FROM tw__Towar WHERE tw_Symbol='".$p->symbol."'";
			$stmt = sqlsrv_query_wrap( $subiekt, $tsql);
			$ret = sqlsrv_fetch_object($stmt);

			//$row_count = sqlsrv_num_rows( $stmt );

			if($ret->ile == 0)
				die('W bazie Subiekta nie ma towaru o Symbolu: '.$p->symbol);
		}
		unset($p);

//czy jest kontrahent o takim symbolu i adresie email
		//if( $order->company != "" && $order->nip != "" ){
			if($order->clientSymbol != '')
				$kh_Symbol = $order->clientSymbol;
			else
				$kh_Symbol = strtoupper(substr(Main::noPolish($order->company), 0,20));
			$kh_Name = $order->company;
		/*}else{
			$kh_Symbol = strtoupper(substr(Main::noPolish($order->name), 0,20));
			$kh_Name = $order->name;
		}*/
		$kh_Symbol = iconv('UTF-8', 'Windows-1250', $kh_Symbol);

		$dodajKH = false;

		$tsql = "SELECT kh_Id, kh_EMail FROM kh__Kontrahent WHERE kh_Symbol='".$kh_Symbol."' ";
		$stmt = sqlsrv_query_wrap( $subiekt, $tsql);
		$ret = sqlsrv_fetch_object($stmt);

		if( sqlsrv_num_rows( $stmt ) == 0 ){
			$dodajKH = true;
		}else{
			//var_dump($ret->kh_EMail);var_dump($order->email);die();
			if( $ret->kh_EMail == $order->email){//jest taki sam kontrahent
				$kh_Id = $ret->kh_Id;
			}else{//jest ale ma inny email, trzeba dodac nowego
				$dodajKH = true;

				for($i = 1; $i<100; $i++){
					//if( $order->company != "" && $order->nip != "" ){
						$kh_Symbol = strtoupper(substr(Main::noPolish($order->company), 0,17)).'-'.$i;
					/*}else{
						$kh_Symbol = strtoupper(substr(Main::noPolish($order->name), 0,17)).'-'.$i;
					}*/
					$kh_Symbol = iconv('UTF-8', 'Windows-1250', $kh_Symbol);

					$tsql = "SELECT kh_Id FROM kh__Kontrahent WHERE kh_Symbol='".$kh_Symbol."'";
					$stmt = sqlsrv_query_wrap( $subiekt, $tsql);
					if( sqlsrv_num_rows( $stmt ) == 0 )
						break;
				}
			}
		}


//dodaj kontrahenta
		if($dodajKH){

			$stmt = sqlsrv_query_wrap( $subiekt, "declare @p3 int
			set @p3=NULL
			exec spIdentyfikator 'kh__Kontrahent',1,@p3 output
			select @p3 as id", true);
			$kh_Id = sqlsrv_fetch_object($stmt)->id;

			//var_dump($kh_Id);

			$stmt = sqlsrv_query_wrap( $subiekt, "declare @p3 int
			set @p3=NULL
			exec spIdentyfikator 'adr__Ewid',1,@p3 output
			select @p3 as id", true);
			$adr_Id = sqlsrv_fetch_object($stmt)->id;

			$stmt = sqlsrv_query_wrap( $subiekt, "declare @p3 int
			set @p3=NULL
			exec spIdentyfikator 'adr__Ewid',1,@p3 output
			select @p3 as id", true);
			$adr2_Id = sqlsrv_fetch_object($stmt)->id;

			$stmt = sqlsrv_query_wrap( $subiekt, "declare @p3 int
			set @p3=NULL
			exec spIdentyfikator 'adr__Ewid',1,@p3 output
			select @p3 as id", true);
			$adr3_Id = sqlsrv_fetch_object($stmt)->id;

			$stmt = sqlsrv_query_wrap( $subiekt, "declare @p3 int
			set @p3=NULL
			exec spIdentyfikator 'tel__Ewid',1,@p3 output
			select @p3 as id", true);
			$tel_Id = sqlsrv_fetch_object($stmt)->id;

			$stmt = sqlsrv_query_wrap( $subiekt, "declare @p3 int
			set @p3=NULL
			exec spIdentyfikator 'adr_Email',1,@p3 output
			select @p3 as id", true);
			$email_Id = sqlsrv_fetch_object($stmt)->id;

			//sqlsrv_query_wrap( $subiekt, "set implicit_transactions on SET NO_BROWSETABLE ON");

			//if( $order->company != "" && $order->nip != "" ){
				$kh_Name = $order->company;
				$kh_NameFull = $order->companyFull;
				$nip = $order->nip;
				$imie = $nazwisko = '';
				$osoba = 0;
			/*}else{
				$kh_Name = $order->name;
				$nip = '';

				$tmp = explode(' ', $order->name);
				$imie = $tmp[0];
				array_shift($tmp);
				$nazwisko = implode(' ', $tmp);

				//$imie = $order->firstname;
				//$nazwisko = $order->lastname;
				$osoba = 1;
			}*/

			$stmt = sqlsrv_query_wrap( $subiekt, "exec sp_executesql N'INSERT INTO \"".registry::get('msdbName')."\"..\"kh__Kontrahent\" 			(\"kh_Id\",\"kh_Symbol\",\"kh_Rodzaj\",\"kh_REGON\",\"kh_Kontakt\",\"kh_CentrumAut\",\"kh_InstKredytowa\",\"kh_PrefKontakt\",\"kh_WWW\",\"kh_EMail\",\"kh_IdGrupa\",\"kh_PlatOdroczone\",\"kh_OdbDet\",\"kh_MaxDokKred\",\"kh_MaxWartDokKred\",\"kh_MaxWartKred\",\"kh_MaxDniSp\",\"kh_NrAnalitykaD\",\"kh_NrAnalitykaO\",\"kh_ZgodaDO\",\"kh_ZgodaMark\",\"kh_ZgodaEMail\",\"kh_CzyKomunikat\",\"kh_Pole1\",\"kh_Pole2\",\"kh_Pole3\",\"kh_Pole4\",\"kh_Pole5\",\"kh_Pole6\",\"kh_Pole7\",\"kh_Pole8\",\"kh_Pracownik\",\"kh_Zablokowany\",\"kh_ProcKarta\",\"kh_ProcKredyt\",\"kh_ProcGotowka\",\"kh_ProcPozostalo\",\"kh_PodVATZarejestrowanyWUE\",\"kh_IdRodzajKontaktu\",\"kh_CRM\",\"kh_Potencjalny\",\"kh_IdDodal\",\"kh_IdZmienil\",\"kh_DataDodania\",\"kh_DataZmiany\",\"kh_ProcPrzelew\",\"kh_Osoba\",\"kh_StatusAkcyza\",\"kh_WzwIdFS\",\"kh_WzwIdWZ\",\"kh_WzwIdWZVAT\",\"kh_WzwIdZK\",\"kh_WzwIdZKZAL\",\"kh_ZgodaNewsletterVendero\",\"kh_KlientSklepuInternetowego\",\"kh_WzwIdZD\",\"kh_WzwIdCrmTransakcja\",\"kh_CelZakupu\",\"kh_BrakPPDlaRozrachunkowAuto\",\"kh_Imie\",\"kh_Nazwisko\")
			VALUES (@P1,@P2,@P3,@P4,@P5,@P6,@P7,@P8,@P9,@P10,@P11,@P12,@P13,@P14,@P15,@P16,@P17,@P18,@P19,@P20,@P21,@P22,@P23,@P24,@P25,@P26,@P27,@P28,@P29,@P30,@P31,@P32,@P33,@P34,@P35,@P36,@P37,@P38,@P39,@P40,@P41,@P42,@P43,@P44,@P45,@P46,@P47,@P48,@P49,@P50,@P51,@P52,@P53,@P54,@P55,@P56,@P57,@P58,@P59,@P60,@P61)',N'@P1 int,@P2 varchar(20),@P3 int,@P4 varchar(1),@P5 varchar(1),@P6 bit,@P7 bit,@P8 varchar(1),@P9 varchar(1),@P10 varchar(100),@P11 int,@P12 bit,@P13 bit,@P14 int,@P15 money,@P16 money,@P17 int,@P18 varchar(1),@P19 varchar(1),@P20 bit,@P21 bit,@P22 bit,@P23 bit,@P24 varchar(1),@P25 varchar(1),@P26 varchar(1),@P27 varchar(1),@P28 varchar(1),@P29 varchar(1),@P30 varchar(1),@P31 varchar(1),@P32 varchar(1),@P33 bit,@P34 money,@P35 money,@P36 money,@P37 money,@P38 bit,@P39 int,@P40 bit,@P41 bit,@P42 int,@P43 int,@P44 datetime,@P45 datetime,@P46 money,@P47 bit,@P48 int,@P49 int,@P50 int,@P51 int,@P52 int,@P53 int,@P54 bit,@P55 bit,@P56 int,@P57 int,@P58 int,@P59 bit,@P60 varchar(20),@P61 varchar(30)',".$kh_Id.",'".$kh_Symbol."',2,'','',0,0,'','','".$order->email."',1,1,0,0,$0.0000,$0.0000,0,'','',0,0,0,0,'','','','','','','','','',0,$0.0000,$0.0000,$100.0000,$0.0000,0,NULL,0,0,1,1,'".date("Y-m-d H:i:s")."','".date("Y-m-d H:i:s")."',$0.0000,".$osoba.",0,0,0,0,0,0,0,0,0,0,0,0,'".iconv('UTF-8', 'Windows-1250',$imie)."','".iconv('UTF-8', 'Windows-1250',$nazwisko)."'", true);



			$stmt = sqlsrv_query_wrap( $subiekt, "exec sp_executesql N'INSERT INTO \"".registry::get('msdbName')."\"..\"adr__Ewid\" (\"adr_Id\",\"adr_IdObiektu\",\"adr_TypAdresu\",\"adr_Nazwa\",\"adr_NazwaPelna\",\"adr_Telefon\",\"adr_Faks\",\"adr_Ulica\",\"adr_NrDomu\",\"adr_NrLokalu\",\"adr_Kod\",\"adr_Miejscowosc\",\"adr_IdWojewodztwo\",\"adr_IdPanstwo\",\"adr_NIP\",\"adr_Poczta\",\"adr_Gmina\",\"adr_Powiat\",\"adr_Skrytka\",\"adr_Symbol\") VALUES (@P1,@P2,@P3,@P4,@P5,@P6,@P7,@P8,@P9,@P10,@P11,@P12,@P13,@P14,@P15,@P16,@P17,@P18,@P19,@P20)',N'@P1 int,@P2 int,@P3 int,@P4 varchar(50),@P5 varchar(255),@P6 varchar(35),@P7 varchar(35),@P8 varchar(60),@P9 varchar(10),@P10 varchar(10),@P11 varchar(8),@P12 varchar(40),@P13 int,@P14 int,@P15 varchar(10),@P16 varchar(10),@P17 varchar(10),@P18 varchar(10),@P19 varchar(10),@P20 varchar(20)',".$adr_Id.",".$kh_Id.",1,'".iconv('UTF-8', 'Windows-1250',$kh_Name)."','".iconv('UTF-8', 'Windows-1250',$kh_NameFull)."','".$order->phone."','','".iconv('UTF-8', 'Windows-1250',$order->street)."','','','".$order->zipCode."','".iconv('UTF-8', 'Windows-1250',$order->town)."',NULL,1,'".$nip."','','','','','".$kh_Symbol."'", true);

			$stmt = sqlsrv_query_wrap( $subiekt, "exec sp_executesql N'INSERT INTO \"".registry::get('msdbName')."\"..\"tel__Ewid\" (\"tel_Id\",\"tel_IdAdresu\",\"tel_Faks\",\"tel_Nazwa\",\"tel_Numer\",\"tel_Podstawowy\") VALUES (@P1,@P2,@P3,@P4,@P5,@P6)',N'@P1 int,@P2 int,@P3 bit,@P4 varchar(1),@P5 varchar(9),@P6 bit',".$tel_Id.",".$adr_Id.",0,'','".$order->phone."',1", true);

			$stmt = sqlsrv_query_wrap( $subiekt, "exec sp_executesql N'INSERT INTO \"".registry::get('msdbName')."\"..\"adr__Ewid\" (\"adr_Id\",\"adr_IdObiektu\",\"adr_TypAdresu\",\"adr_Nazwa\",\"adr_NazwaPelna\",\"adr_Telefon\",\"adr_Faks\",\"adr_Ulica\",\"adr_NrDomu\",\"adr_NrLokalu\",\"adr_Kod\",\"adr_Miejscowosc\",\"adr_IdWojewodztwo\",\"adr_IdPanstwo\",\"adr_NIP\",\"adr_Poczta\",\"adr_Gmina\",\"adr_Powiat\",\"adr_Skrytka\") VALUES (@P1,@P2,@P3,@P4,@P5,@P6,@P7,@P8,@P9,@P10,@P11,@P12,@P13,@P14,@P15,@P16,@P17,@P18,@P19)',N'@P1 int,@P2 int,@P3 int,@P4 varchar(1),@P5 varchar(1),@P6 varchar(1),@P7 varchar(1),@P8 varchar(1),@P9 varchar(1),@P10 varchar(1),@P11 varchar(1),@P12 varchar(1),@P13 int,@P14 int,@P15 varchar(1),@P16 varchar(1),@P17 varchar(1),@P18 varchar(1),@P19 varchar(1)',".$adr2_Id.",".$kh_Id.",2,'','','','','','','','','',NULL,1,'','','','',''", true);

			$stmt = sqlsrv_query_wrap( $subiekt, "exec sp_executesql N'INSERT INTO \"".registry::get('msdbName')."\"..\"adr__Ewid\" (\"adr_Id\",\"adr_IdObiektu\",\"adr_TypAdresu\",\"adr_IdWojewodztwo\",\"adr_IdPanstwo\") VALUES (@P1,@P2,@P3,@P4,@P5)',N'@P1 int,@P2 int,@P3 int,@P4 int,@P5 int',".$adr3_Id.",".$kh_Id.",11,NULL,1", true);

			$stmt = sqlsrv_query_wrap( $subiekt, "exec sp_executesql N'INSERT INTO \"".registry::get('msdbName')."\"..\"adr_Email\" (\"am_Id\",\"am_IdAdres\",\"am_Email\",\"am_Rodzaj\",\"am_Podstawowy\") VALUES (@P1,@P2,@P3,@P4,@P5)',N'@P1 int,@P2 int,@P3 varchar(24),@P4 int,@P5 bit',".$email_Id.",".$adr_Id.",'".$order->email."',1,1", true);

			//sqlsrv_query_wrap( $subiekt, "IF @@TRANCOUNT > 0 COMMIT TRAN");
			//sqlsrv_query_wrap( $subiekt, "set implicit_transactions off SET NO_BROWSETABLE off");
		}
		//var_dump($kh_Id); die();

//dodaj zamowienie
	//stale
		$miasto = 'Wadowice';
		$mag_Id = 1;

		$stmt = sqlsrv_query_wrap( $subiekt, "declare @p3 varchar(3)
		set @p3=NULL
		exec sp_executesql N'SELECT TOP 1 @P1=twp_WalutaCeny2 FROM tw_Parametr',N'@P1 varchar(3) OUTPUT',@p3 output
		select @p3 as waluta", true);
		$waluta = sqlsrv_fetch_object($stmt)->waluta;

		$stmt = sqlsrv_query_wrap( $subiekt, "declare @p3 int
		set @p3=NULL
		exec spIdentyfikator 'dok__dokument',1,@p3 output
		select @p3 as id", true);
		$dok_Id = sqlsrv_fetch_object($stmt)->id;

		$stmt = sqlsrv_query_wrap( $subiekt, "SELECT mag_Symbol FROM sl_Magazyn WHERE mag_Id = ".$mag_Id);
		$mag_Symbol = sqlsrv_fetch_object($stmt)->mag_Symbol;

		$stmt = sqlsrv_query_wrap( $subiekt, "SELECT uz_Identyfikator, uz_Imie + ' ' + uz_Nazwisko AS ImieNazwisko FROM pd_Uzytkownik WHERE uz_Id=1");
		$uz = sqlsrv_fetch_object($stmt);
		$uz_Ident = $uz->uz_Identyfikator; //IVO
		$uz_Nazwa = $uz->ImieNazwisko;

		$stmt = sqlsrv_query_wrap( $subiekt, "SELECT IDENT, WALUTA, RODZAJ FROM vwPoziomyCenWaluty WHERE NAZWA='".iconv('UTF-8', 'Windows-1250','Hurtowa')."'");
		$dok_CenyPoziom = sqlsrv_fetch_object($stmt)->IDENT;

		//$dok_CenyTyp = 1;//netto
		$dok_CenyTyp = 0;//brutto

		$stmt = sqlsrv_query_wrap( $subiekt, "SELECT kat_Id FROM sl_Kategoria WHERE kat_Nazwa='".iconv('UTF-8', 'Windows-1250','Sprzedaż')."'");
		$kat_Id = sqlsrv_fetch_object($stmt)->kat_Id;


		$stmt = sqlsrv_query_wrap( $subiekt, "declare @p3 int
		set @p3=NULL
		exec spIdentyfikator 'adr_Historia',2,@p3 output
		select @p3 as id", true);
		$adrh_Id = sqlsrv_fetch_object($stmt)->id;

		$stmt = sqlsrv_query_wrap( $subiekt, "EXEC spSub_DodajHistorieAdresowa ".$adrh_Id.", ".$kh_Id.", 1, '".$kh_Symbol."', '".iconv('UTF-8', 'Windows-1250',$kh_Name)."', '".iconv('UTF-8', 'Windows-1250',$kh_Name)."', '".iconv('UTF-8', 'Windows-1250',$order->street)."', '".$order->zipCode."', '".iconv('UTF-8', 'Windows-1250',$order->town)."', '".$nip."'", true);

		$produkty = array();
		$wartNetto = 0;
		$wartBrutto = 0;
		$wartNettoTw = 0;
		$wartBruttoTw = 0;
		$wartNettoUs = 0;
		$wartBruttoUs = 0;

		//$nr_Dok = '/'.$uz_Ident.'/'.$mag_Symbol.'/'.date('Y');
		$nr_Dok = '/'.$mag_Symbol.'/'.date('m').'/'.date('Y');

		foreach($pos as $p){
			if( $p->price == 0 )
				continue;

			$stmt = sqlsrv_query_wrap( $subiekt, "SELECT tw_Id, tw_Rodzaj, tw_Opis, tw_Nazwa, tw_PKWiU, tw_DostSymbol, tw_IdGrupa, tw_Objetosc, tw_Masa, tw_Akcyza, tw_AkcyzaZaznacz, tw_AkcyzaKwota, tw_ObrotMarza, tw_OdwrotneObciazenie, tw_ProgKwotowyOO , vat_Stawka, tw_IdVatSp
			FROM tw__Towar LEFT JOIN sl_StawkaVAT ON vat_Id = tw_IdVatSp
			WHERE tw_Symbol='".$p->symbol."' ");

			$tw = sqlsrv_fetch_object($stmt);

			$pr = new stdClass();
			$pr->id = $tw->tw_Id;
			$pr->vat = $tw->vat_Stawka;
			$pr->vatId = $tw->tw_IdVatSp;
			$pr->rodzaj = $p->symbol == 'KTRANSP' ? 2 : 1;
			$pr->priceBrutto = $p->price;
			$pr->priceNetto = $p->price/(1+$pr->vat/100);
			$pr->amount = $p->amount;


			$produkty[] = $pr;

			if($pr->rodzaj == 1){//towar
				$wartNettoTw += ($p->price * $p->amount)/(1+$pr->vat/100);
				$wartBruttoTw += $p->price * $p->amount;
			}else{
				$wartNettoUs += ($p->price * $p->amount)/(1+$pr->vat/100);
				$wartBruttoUs += $p->price * $p->amount;
			}
			$wartNetto += ($p->price * $p->amount)/(1+$pr->vat/100);
			$wartBrutto += $p->price * $p->amount;

			//$stmt = sqlsrv_query_wrap( $subiekt, "SELECT vat_Id FROM vwStawkaVAT WHERE vat_Stawka=23");
			//$stmt = sqlsrv_query_wrap( $subiekt, "SELECT vat_Nazwa, vat_Symbol, vat_Stawka, vat_Pozycja FROM sl_StawkaVat WHERE vat_id = 100001");
		}
		$wartVat = $wartBrutto - $wartNetto;
		$uwagi = 'Nr Zamówienia: '.$order->id.'; ';

		if($order->ad_name != ''){
			$uwagi .= 'Adres dostawy: ';
			$uwagi .= $order->ad_name.', ';
			if(!empty($order->ad_company))
			  $uwagi .= 'Firma: '.$order->ad_company.', ';

			$uwagi .= $order->ad_street.', '.
				$order->ad_zipCode.' '.$order->ad_town.', '.$order->ad_country.'; Tel. '.$order->ad_phone;
		}

		$stmt = sqlsrv_query_wrap( $subiekt, "exec sp_executesql N'INSERT INTO \"".registry::get('msdbName')."\"..\"dok__Dokument\" 		(\"dok_Id\",\"dok_Typ\",\"dok_Podtyp\",\"dok_MagId\",\"dok_Nr\",\"dok_NrRoz\",\"dok_NrPelny\",\"dok_NrPelnyOryg\",\"dok_DoDokNrPelny\",\"dok_MscWyst\",\"dok_DataWyst\",\"dok_DataMag\",\"dok_DataOtrzym\",\"dok_PlatnikId\",\"dok_PlatnikAdreshId\",\"dok_OdbiorcaId\",\"dok_OdbiorcaAdreshId\",\"dok_PlatId\",\"dok_PlatTermin\",\"dok_Wystawil\",\"dok_Odebral\",\"dok_PersonelId\",\"dok_CenyPoziom\",\"dok_CenyTyp\",\"dok_CenyKurs\",\"dok_RabatProc\",\"dok_WartUsNetto\",\"dok_WartUsBrutto\",\"dok_WartTwNetto\",\"dok_WartTwBrutto\",\"dok_WartOpZwr\",\"dok_WartOpWyd\",\"dok_WartMag\",\"dok_WartNetto\",\"dok_WartVat\",\"dok_WartBrutto\",\"dok_KwGotowka\",\"dok_KwKarta\",\"dok_KwDoZaplaty\",\"dok_KwKredyt\",\"dok_Waluta\",\"dok_WalutaKurs\",\"dok_Uwagi\",\"dok_KatId\",\"dok_Tytul\",\"dok_Podtytul\",\"dok_Status\",\"dok_StatusKsieg\",\"dok_StatusFiskal\",\"dok_StatusBlok\",\"dok_JestTylkoDoOdczytu\",\"dok_JestRuchMag\",\"dok_JestZmianaDatyDokKas\",\"dok_JestHOP\",\"dok_RodzajOperacjiVat\",\"dok_JestVatAuto\",\"dok_Algorytm\",\"dok_KartaId\",\"dok_KredytId\",\"dok_WartMagP\",\"dok_WartMagR\",\"dok_KwWartosc\",\"dok_CenyNarzut\",\"dok_KodRodzajuTransakcji\",\"dok_StatusEx\",\"dok_Rozliczony\",\"dok_TerminRealizacji\",\"dok_obiektgt\",\"dok_WalutaLiczbaJednostek\",\"dok_WalutaRodzajKursu\",\"dok_CenyLiczbaJednostek\",\"dok_CenyRodzajKursu\",\"dok_KwPrzelew\",\"dok_KwGotowkaPrzedplata\",\"dok_KwPrzelewPrzedplata\",\"dok_DefiniowalnyId\",\"dok_TransakcjaId\",\"dok_TransakcjaSymbol\",\"dok_TransakcjaData\",\"dok_PodsumaVatFSzk\",\"dok_ZlecenieId\",\"dok_NaliczajFundusze\",\"dok_PrzetworzonoZKwZD\",\"dok_VatMarza\",\"dok_DstNr\",\"dok_DstNrRoz\",\"dok_DstNrPelny\",\"dok_ObslugaDokDost\",\"dok_AkcyzaZwolnienieId\",\"dok_ProceduraMarzy\",\"dok_FakturaUproszczona\",\"dok_DataZakonczenia\",\"dok_MetodaKasowa\",\"dok_TypNrIdentNabywcy\",\"dok_NrIdentNabywcy\",\"dok_AdresDostawyId\",\"dok_AdresDostawyAdreshId\",\"dok_VenderoId\",\"dok_VenderoSymbol\",\"dok_VenderoData\",\"dok_SelloId\",\"dok_SelloSymbol\",\"dok_SelloData\",\"dok_TransakcjaJednolitaId\",\"dok_UwagiExt\",\"dok_VenderoStatus\",\"dok_ZaimportowanoDoEwidencjiAkcyzowej\",\"dok_TermPlatStatus\",\"dok_TermPlatTransId\",\"dok_DokumentFiskalnyDlaPodatnikaVat\",\"dok_CesjaPlatnikOdbiorca\",\"dok_WartOplRecykl\",\"dok_TermPlatIdKonfig\",\"dok_TermPlatIdZadania\")
		VALUES
		(@P1,@P2,@P3,@P4,@P5,@P6,@P7,@P8,@P9,@P10,@P11,@P12,@P13,@P14,@P15,@P16,@P17,@P18,@P19,@P20,@P21,@P22,@P23,@P24,@P25,@P26,@P27,@P28,@P29,@P30,@P31,@P32,@P33,@P34,@P35,@P36,@P37,@P38,@P39,@P40,@P41,@P42,@P43,@P44,@P45,@P46,@P47,@P48,@P49,@P50,@P51,@P52,@P53,@P54,@P55,@P56,@P57,@P58,@P59,@P60,@P61,@P62,@P63,@P64,@P65,@P66,@P67,@P68,@P69,@P70,@P71,@P72,@P73,@P74,@P75,@P76,@P77,@P78,@P79,@P80,@P81,@P82,@P83,@P84,@P85,@P86,@P87,@P88,@P89,@P90,@P91,@P92,@P93,@P94,@P95,@P96,@P97,@P98,@P99,@P100,@P101,@P102,@P103,@P104,@P105,@P106,@P107,@P108,@P109,@P110,@P111,@P112,@P113,@P114)',N'@P1
		int,@P2 int,@P3 int,@P4 int,@P5 int,@P6 varchar(3),@P7 varchar(30),@P8 varchar(30),@P9 varchar(1),@P10 varchar(40),@P11 datetime,@P12 datetime,@P13 datetime,@P14 int,@P15 int,@P16 int,@P17 int,@P18 int,@P19 datetime,@P20 varchar(4),@P21 varchar(17),@P22 int,@P23 int,@P24 int,@P25 money,@P26 money,@P27 money,@P28 money,@P29 money,@P30 money,@P31 money,@P32 money,@P33 money,@P34 money,@P35 money,@P36 money,@P37 money,@P38 money,@P39 money,@P40 money,@P41 varchar(3),@P42 money,@P43 varchar(500),@P44 int,@P45 varchar(21),@P46 varchar(1),@P47 int,@P48 int,@P49 int,@P50 bit,@P51 bit,@P52 bit,@P53 bit,@P54 bit,@P55 int,@P56 bit,@P57 int,@P58 int,@P59 int,@P60 money,@P61 money,@P62 money,@P63 money,@P64 int,@P65 int,@P66 bit,@P67 datetime,@P68 int,@P69 int,@P70 int,@P71 int,@P72 int,@P73 money,@P74 money,@P75 money,@P76 int,@P77 int,@P78 varchar(1),@P79 datetime,@P80 int,@P81 int,@P82 bit,@P83 bit,@P84 bit,@P85 int,@P86 varchar(1),@P87 varchar(1),@P88 int,@P89 int,@P90 int,@P91 bit,@P92 datetime,@P93 bit,@P94 int,@P95 varchar(1),@P96 int,@P97 int,@P98 int,@P99 varchar(1),@P100 datetime,@P101 int,@P102 varchar(1),@P103 datetime,@P104 int,@P105 varchar(1),@P106 int,@P107 bit,@P108 int,@P109 nvarchar(1),@P110 bit,@P111 bit,@P112 money,@P113 int,@P114 int',".$dok_Id.",16,0,".$mag_Id.",0,'".$uz_Ident."','".$nr_Dok."','".$order->id."','','".$miasto."','".date("Y-m-d")." 00:00:00','".date("Y-m-d")." 00:00:00',NULL,".$kh_Id.",".$adrh_Id.",".$kh_Id.",".$adrh_Id.",NULL,'".date("Y-m-d")." 00:00:00','Szef','',1,".$dok_CenyPoziom.",".$dok_CenyTyp.",$1.0000,$0.0000,$".$wartNettoUs.",$".$wartBruttoUs.",$".$wartNettoTw.",$".$wartBruttoTw.",$0.0000,$0.0000,$0,$".$wartNetto.",$".$wartVat.",$".$wartBrutto.",$0,$0.0000,$0.0000,$0.0000,'".$waluta."',$1.0000,'".iconv('UTF-8', 'Windows-1250',$uwagi)."',".$kat_Id.",'".iconv('UTF-8', 'Windows-1250','Zamówienie od klienta')."','',7,0,0,0,0,1,1,0,0,1,0,3,10,$0.0000,$0,$".$wartBrutto.",$0.0000,NULL,0,0,'".date("Y-m-d")." 00:00:00',-8,1,1,1,1,$0.0000,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,0,NULL,0,0,NULL,NULL,NULL,0,NULL,NULL,0,NULL,NULL,NULL,'',NULL,0,NULL,NULL,0,0,$0.0000,NULL,NULL", true);

//dodaj pozycje

		foreach($produkty as $p){
			$stmt = sqlsrv_query_wrap( $subiekt, "declare @p3 int
			set @p3=NULL
			exec spIdentyfikator 'dok_pozycja',1,@p3 output
			select @p3 as id", true);
			$poz_Id = sqlsrv_fetch_object($stmt)->id;

			$stmt = sqlsrv_query_wrap( $subiekt, "exec sp_executesql N'INSERT INTO \"".registry::get('msdbName')."\"..\"dok_Pozycja\" (\"ob_Id\",\"ob_DoId\",\"ob_Znak\",\"ob_Status\",\"ob_DokHanId\",\"ob_DokMagId\",\"ob_TowId\",\"ob_TowRodzaj\",\"ob_Opis\",\"ob_DokHanLp\",\"ob_DokMagLp\",\"ob_Ilosc\",\"ob_IloscMag\",\"ob_Jm\",\"ob_CenaWaluta\",\"ob_CenaNetto\",\"ob_CenaBrutto\",\"ob_Rabat\",\"ob_WartNetto\",\"ob_WartVat\",\"ob_WartBrutto\",\"ob_VatId\",\"ob_VatProc\",\"ob_Termin\",\"ob_MagId\",\"ob_NumerSeryjny\",\"ob_KategoriaId\",\"ob_Akcyza\",\"ob_AkcyzaKwota\",\"ob_AkcyzaWartosc\",\"ob_PrzyczynaKorektyId\",\"ob_CenaPobranaZCennika\",\"ob_TowPkwiu\") VALUES (@P1,@P2,@P3,@P4,@P5,@P6,@P7,@P8,@P9,@P10,@P11,@P12,@P13,@P14,@P15,@P16,@P17,@P18,@P19,@P20,@P21,@P22,@P23,@P24,@P25,@P26,@P27,@P28,@P29,@P30,@P31,@P32,@P33)',N'@P1 int,@P2 int,@P3 smallint,@P4 int,@P5 int,@P6 int,@P7 int,@P8 int,@P9 varchar(1),@P10 int,@P11 int,@P12 money,@P13 money,@P14 varchar(4),@P15 money,@P16 money,@P17 money,@P18 money,@P19 money,@P20 money,@P21 money,@P22 int,@P23 money,@P24 datetime,@P25 int,@P26 varchar(1),@P27 int,@P28 bit,@P29 money,@P30 money,@P31 int,@P32 int,@P33 varchar(1)',".$poz_Id.",NULL,1,1,".$dok_Id.",NULL,".$p->id.",".$p->rodzaj.",'',1,1,$".$p->amount.",$".$p->amount.",'szt.',$".$p->priceNetto.",$".$p->priceNetto.",$".$p->priceBrutto.",$0.0000,$".($p->priceNetto*$p->amount).",$".($p->priceBrutto*$p->amount - $p->priceNetto*$p->amount).",$".($p->priceBrutto*$p->amount).",".$p->vatId.",$".$p->vat.",NULL,1,NULL,NULL,0,NULL,NULL,NULL,NULL,''", true);

			//rezerwuj stany
			$stmt = sqlsrv_query_wrap( $subiekt, "exec spSub_DokRezerwZwieksz ".$mag_Id.",".$p->id.",$".$p->amount."", true);

			/*$stmt = sqlsrv_query_wrap( $subiekt, "exec sp_executesql N'UPDATE \"".registry::get('msdbName')."\"..\"dok_Pozycja\" SET \"ob_CenaMag\"=@P1,\"ob_WartMag\"=@P2 WHERE \"ob_Id\"=@P3 AND \"ob_CenaMag\"=@P4 AND \"ob_WartMag\"=@P5',N'@P1 money,@P2 money,@P3 int,@P4 money,@P5 money',$23.0900,$0.0000,".$poz_Id.",$0.0000,$0.0000", true);*/
		}
		/*$stmt = sqlsrv_query_wrap( $subiekt, \"exec sp_executesql N'UPDATE \"".registry::get('msdbName')."\"..\"dok__Dokument\" SET \"dok_WartUsNetto\"=@P1,\"dok_WartUsBrutto\"=@P2,\"dok_WartTwNetto\"=@P3,\"dok_WartTwBrutto\"=@P4,\"dok_WartOpZwr\"=@P5,\"dok_WartOpWyd\"=@P6,\"dok_WartMag\"=@P7,\"dok_WartMagP\"=@P8,\"dok_WartMagR\"=@P9,\"dok_obiektgt\"=@P10,\"dok_WartOplRecykl\"=@P11 WHERE \"dok_Id\"=@P12 AND \"dok_WartUsNetto\"=@P13 AND \"dok_WartUsBrutto\"=@P14 AND \"dok_WartTwNetto\"=@P15 AND \"dok_WartTwBrutto\"=@P16 AND \"dok_WartOpZwr\"=@P17 AND \"dok_WartOpWyd\"=@P18 AND \"dok_WartMag\"=@P19 AND \"dok_WartMagP\"=@P20 AND \"dok_WartMagR\"=@P21 AND \"dok_obiektgt\"=@P22 AND \"dok_WartOplRecykl\"=@P23',N'@P1 money,@P2 money,@P3 money,@P4 money,@P5 money,@P6 money,@P7 money,@P8 money,@P9 money,@P10 int,@P11 money,@P12 int,@P13 money,@P14 money,@P15 money,@P16 money,@P17 money,@P18 money,@P19 money,@P20 money,@P21 money,@P22 int,@P23 money',$0.0000,$0.0000,$810.0000,$874.8000,$0.0000,$0.0000,$0.0000,$0.0000,$0.0000,-8,$0.0000,".$dok_Id.",$0.0000,$0.0000,$810.0000,$874.8000,$0.0000,$0.0000,$0,$0.0000,$0,-8,$0.0000", true);*/

		$mysql->query("UPDATE ivon_orders SET zk_subiekt = '1' WHERE id = ".$order->id);
	}
//-------------dodawanie zamowienia ZK--------------------
//kontrahent i towar - po symbolu - bez rezerwacji stanu
/*	SELECT * FROM su_Parametr
	SELECT * FROM sl_StawkaVat
	SELECT * FROM kom_Parametr

	SELECT TOP 1 kh_Id, adr_Id, adrh_Id FROM kh__Kontrahent AS a  INNER JOIN adr__Ewid AS b ON a.kh_Id=b.adr_IdObiektu INNER JOIN adr_Historia AS c ON b.adr_Id=c.adrh_IdAdresu  WHERE adr_TypAdresu=1 AND kh_Symbol='ARTUR' ORDER BY adrh_Id DESC

	SELECT tw_Id, tw_Symbol FROM tw__Towar WHERE tw_Symbol='PESO20'


	//pobranie waluty
	declare @p3 varchar(3)
	set @p3=NULL
	exec sp_executesql N'SELECT TOP 1 @P1=twp_WalutaCeny2 FROM tw_Parametr',N'@P1 varchar(3) OUTPUT',@p3 output
	select @p3

	SELECT * FROM nr_Parametr WHERE np_Typ = 16 AND np_DefiniowalnyId = 0
	SELECT mag_Symbol FROM sl_Magazyn WHERE mag_Id = 1

	select *, ltrim(rtrim(uz_Nazwisko+' '+uz_Imie)) as uz_NazwiskoImie,ltrim(rtrim(uz_Imie+' '+uz_Nazwisko)) as uz_ImieNazwisko from pd_Uzytkownik where uz_status=1
	SELECT * FROM pw_Pole JOIN pw_RelacjaDokDef ON pwp_Id=pwr_IdPwp AND pwr_IdDokDef = 0 AND pwr_Dostepne = 1 WHERE pwp_TypObiektu=-8

	SELECT TOP 1 kh_Id, adr_Id, adrh_Id FROM kh__Kontrahent AS a  INNER JOIN adr__Ewid AS b ON a.kh_Id=b.adr_IdObiektu INNER JOIN adr_Historia AS c ON b.adr_Id=c.adrh_IdAdresu  WHERE adr_TypAdresu=1 AND kh_Symbol='ARTUR' ORDER BY adrh_Id DESC

	SELECT adrh_Id FROM adr_Historia AS a INNER JOIN adr__Ewid AS b ON a.adrh_IdAdresu=b.adr_Id WHERE b.adr_IdObiektu=13 AND a.adrh_Nazwa='Kiosk ARTUR' AND a.adrh_NazwaPelna='Kiosk ARTUR' AND a.adrh_Miejscowosc='Lublin' AND a.adrh_Kod='96-534' AND a.adrh_Adres='Legnicka  57/2' AND a.adrh_NIP='836-84-63-635'

	SELECT uz_Imie + ' ' + uz_Nazwisko AS ImieNazwisko FROM pd_Uzytkownik WHERE uz_Id=3
	SELECT uz_Id FROM pd_Uzytkownik WHERE LTRIM(RTRIM(uz_Imie + ' ' + uz_Nazwisko))=LTRIM(RTRIM(' Szef')) OR uz_Identyfikator=''
	SELECT IDENT, WALUTA, RODZAJ FROM vwPoziomyCenWaluty WHERE NAZWA='Hurtowa'
	SELECT fp_Id FROM sl_FormaPlatnosci WHERE fp_Nazwa='24 miesiace' AND fp_Typ=3
	SELECT fp_Id FROM sl_FormaPlatnosci WHERE fp_Nazwa='Karta platnicza' AND fp_Typ=1
	SELECT wl_LiczbaJednostek FROM sl_Waluta WHERE wl_Symbol='PLN'
	SELECT kat_Id FROM sl_Kategoria WHERE kat_Nazwa='Sprzedaz'

	declare @p3 int
	set @p3=NULL
	exec spIdentyfikator 'sl_Kategoria',1,@p3 output
	select @p3

	exec sp_executesql N'INSERT INTO "test1".."sl_Kategoria" ("kat_Id","kat_Nazwa","kat_Typ","kat_Podtytul") VALUES (@P1,@P2,@P3,@P4)',N'@P1 int,@P2 varchar(8),@P3 int,@P4 varchar(20)',12,'Sprzedaz',2,'Sprzedaz dla klienta'



	declare @p3 int
set @p3=NULL
exec spIdentyfikator 'dok__dokument',1,@p3 output
select @p3


	exec sp_executesql N'INSERT INTO "test1".."dok__Dokument" ("dok_Id","dok_Typ","dok_Podtyp","dok_MagId","dok_Nr","dok_NrRoz","dok_NrPelny","dok_NrPelnyOryg","dok_DoDokNrPelny","dok_MscWyst","dok_DataWyst","dok_DataMag","dok_DataOtrzym","dok_PlatnikId","dok_PlatnikAdreshId","dok_OdbiorcaId","dok_OdbiorcaAdreshId","dok_PlatId","dok_PlatTermin","dok_Wystawil","dok_Odebral","dok_PersonelId","dok_CenyPoziom","dok_CenyTyp","dok_CenyKurs","dok_RabatProc","dok_WartUsNetto","dok_WartUsBrutto","dok_WartTwNetto","dok_WartTwBrutto","dok_WartOpZwr","dok_WartOpWyd","dok_WartMag","dok_WartNetto","dok_WartVat","dok_WartBrutto","dok_KwGotowka","dok_KwKarta","dok_KwDoZaplaty","dok_KwKredyt","dok_Waluta","dok_WalutaKurs","dok_Uwagi","dok_KatId","dok_Tytul","dok_Podtytul","dok_Status","dok_StatusKsieg","dok_StatusFiskal","dok_StatusBlok","dok_JestTylkoDoOdczytu","dok_JestRuchMag","dok_JestZmianaDatyDokKas","dok_JestHOP","dok_RodzajOperacjiVat","dok_JestVatAuto","dok_Algorytm","dok_KartaId","dok_KredytId","dok_WartMagP","dok_WartMagR","dok_KwWartosc","dok_CenyNarzut","dok_KodRodzajuTransakcji","dok_StatusEx","dok_Rozliczony","dok_TerminRealizacji","dok_obiektgt","dok_WalutaLiczbaJednostek","dok_WalutaRodzajKursu","dok_WalutaIdBanku","dok_CenyLiczbaJednostek","dok_CenyRodzajKursu","dok_KwPrzelew","dok_KwGotowkaPrzedplata","dok_KwPrzelewPrzedplata","dok_DefiniowalnyId","dok_TransakcjaId","dok_TransakcjaSymbol","dok_TransakcjaData","dok_PodsumaVatFSzk","dok_ZlecenieId","dok_NaliczajFundusze","dok_PrzetworzonoZKwZD","dok_VatMarza","dok_DstNr","dok_DstNrRoz","dok_DstNrPelny","dok_ObslugaDokDost","dok_AkcyzaZwolnienieId","dok_ProceduraMarzy","dok_FakturaUproszczona","dok_DataZakonczenia","dok_MetodaKasowa","dok_TypNrIdentNabywcy","dok_NrIdentNabywcy","dok_AdresDostawyId","dok_AdresDostawyAdreshId","dok_VenderoId","dok_VenderoSymbol","dok_VenderoData","dok_SelloId","dok_SelloSymbol","dok_SelloData","dok_TransakcjaJednolitaId","dok_UwagiExt","dok_VenderoStatus","dok_ZaimportowanoDoEwidencjiAkcyzowej","dok_TermPlatStatus","dok_TermPlatTransId","dok_DokumentFiskalnyDlaPodatnikaVat","dok_CesjaPlatnikOdbiorca","dok_WartOplRecykl","dok_TermPlatIdKonfig","dok_TermPlatIdZadania") VALUES
(@P1,@P2,@P3,@P4,@P5,@P6,@P7,@P8,@P9,@P10,@P11,@P12,@P13,@P14,@P15,@P16,@P17,@P18,@P19,@P20,@P21,@P22,@P23,@P24,@P25,@P26,@P27,@P28,@P29,@P30,@P31,@P32,@P33,@P34,@P35,@P36,@P37,@P38,@P39,@P40,@P41,@P42,@P43,@P44,@P45,@P46,@P47,@P48,@P49,@P50,@P51,@P52,@P53,@P54,@P55,@P56,@P57,@P58,@P59,@P60,@P61,@P62,@P63,@P64,@P65,@P66,@P67,@P68,@P69,@P70,@P71,@P72,@P73,@P74,@P75,@P76,@P77,@P78,@P79,@P80,@P81,@P82,@P83,@P84,@P85,@P86,@P87,@P88,@P89,@P90,@P91,@P92,@P93,@P94,@P95,@P96,@P97,@P98,@P99,@P100,@P101,@P102,@P103,@P104,@P105,@P106,@P107,@P108,@P109,@P110,@P111,@P112,@P113,@P114,@P115)',N'@P1
int,@P2 int,@P3 int,@P4 int,@P5 int,@P6 varchar(3),@P7 varchar(12),@P8 varchar(1),@P9 varchar(1),@P10 varchar(7),@P11 datetime,@P12 datetime,@P13 datetime,@P14 int,@P15 int,@P16 int,@P17 int,@P18 int,@P19 datetime,@P20 varchar(4),@P21 varchar(17),@P22 int,@P23 int,@P24 int,@P25 money,@P26 money,@P27 money,@P28 money,@P29 money,@P30 money,@P31 money,@P32 money,@P33 money,@P34 money,@P35 money,@P36 money,@P37 money,@P38 money,@P39 money,@P40 money,@P41 varchar(3),@P42 money,@P43 varchar(1),@P44 int,@P45 varchar(21),@P46 varchar(1),@P47 int,@P48 int,@P49 int,@P50 bit,@P51 bit,@P52 bit,@P53 bit,@P54 bit,@P55 int,@P56 bit,@P57 int,@P58 int,@P59 int,@P60 money,@P61 money,@P62 money,@P63 money,@P64 int,@P65 int,@P66 bit,@P67 datetime,@P68 int,@P69 int,@P70 int,@P71 int,@P72 int,@P73 int,@P74 money,@P75 money,@P76 money,@P77 int,@P78 int,@P79 varchar(1),@P80 datetime,@P81 int,@P82 int,@P83 bit,@P84 bit,@P85 bit,@P86 int,@P87 varchar(1),@P88 varchar(1),@P89 int,@P90 int,@P91 int,@P92 bit,@P93 datetime,@P94 bit,@P95 int,@P96 varchar(1),@P97 int,@P98 int,@P99 int,@P100 varchar(1),@P101 datetime,@P102 int,@P103 varchar(1),@P104 datetime,@P105 int,@P106 varchar(1),@P107 int,@P108 bit,@P109 int,@P110 nvarchar(1),@P111 bit,@P112 bit,@P113 money,@P114 int,@P115 int',
95,16,0,1,0,'JK ','/JK/MAG/2018','','','Wroclaw','2018-08-27 00:00:00','2018-08-27 00:00:00',NULL,13,43,13,43,NULL,'2018-08-27 00:00:00','Szef','Jerzy Kwiatkowski',3,2,1,$1.0000,$0.0000,$0.0000,$0.0000,$810.0000,$874.8000,$0.0000,$0.0000,$69.2700,$810.0000,$64.8000,$874.8000,$874.8000,$0.0000,$0.0000,$0.0000,'PLN',$1.0000,'',12,'Zamówienie od klienta','',6,0,0,0,0,0,1,0,0,1,0,3,10,$0.0000,$69.2700,$874.8000,$0.0000,NULL,0,0,'2018-08-27 00:00:00',-8,1,1,1,1,1,$0.0000,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,0,0,'2018-08-27 00:00:00',0,0,NULL,NULL,NULL,0,NULL,NULL,0,NULL,NULL,NULL,'',NULL,0,NULL,NULL,0,0,$0.0000,NULL,NULL


	declare @p3 int
set @p3=NULL
exec spIdentyfikator 'dok_pozycja',1,@p3 output
select @p3

exec sp_executesql N'INSERT INTO "test1".."dok_Pozycja" ("ob_Id","ob_DoId","ob_Znak","ob_Status","ob_DokHanId","ob_DokMagId","ob_TowId","ob_TowRodzaj","ob_Opis","ob_DokHanLp","ob_DokMagLp","ob_Ilosc","ob_IloscMag","ob_Jm","ob_CenaWaluta","ob_CenaNetto","ob_CenaBrutto","ob_Rabat","ob_WartNetto","ob_WartVat","ob_WartBrutto","ob_VatId","ob_VatProc","ob_Termin","ob_MagId","ob_NumerSeryjny","ob_KategoriaId","ob_Akcyza","ob_AkcyzaKwota","ob_AkcyzaWartosc","ob_PrzyczynaKorektyId","ob_CenaPobranaZCennika","ob_TowPkwiu") VALUES (@P1,@P2,@P3,@P4,@P5,@P6,@P7,@P8,@P9,@P10,@P11,@P12,@P13,@P14,@P15,@P16,@P17,@P18,@P19,@P20,@P21,@P22,@P23,@P24,@P25,@P26,@P27,@P28,@P29,@P30,@P31,@P32,@P33)',N'@P1 int,@P2 int,@P3 smallint,@P4 int,@P5 int,@P6 int,@P7 int,@P8 int,@P9 varchar(1),@P10 int,@P11 int,@P12 money,@P13 money,@P14 varchar(4),@P15 money,@P16 money,@P17 money,@P18 money,@P19 money,@P20 money,@P21 money,@P22 int,@P23 money,@P24 datetime,@P25 int,@P26 varchar(1),@P27 int,@P28 bit,@P29 money,@P30 money,@P31 int,@P32 int,@P33 varchar(1)',318,NULL,1,1,95,NULL,10,1,'',1,1,$3.0000,$3.0000,'szt.',$270.0000,$270.0000,$291.6000,$0.0000,$810.0000,$64.8000,$874.8000,100002,$8.0000,NULL,1,NULL,NULL,0,NULL,NULL,NULL,NULL,''

exec sp_executesql N'UPDATE "test1".."dok_Pozycja" SET "ob_CenaMag"=@P1,"ob_WartMag"=@P2 WHERE "ob_Id"=@P3 AND "ob_CenaMag"=@P4 AND "ob_WartMag"=@P5',N'@P1 money,@P2 money,@P3 int,@P4 money,@P5 money',$23.0900,$0.0000,318,$0.0000,$0.0000

exec sp_executesql N'UPDATE "test1".."dok__Dokument" SET "dok_WartUsNetto"=@P1,"dok_WartUsBrutto"=@P2,"dok_WartTwNetto"=@P3,"dok_WartTwBrutto"=@P4,"dok_WartOpZwr"=@P5,"dok_WartOpWyd"=@P6,"dok_WartMag"=@P7,"dok_WartMagP"=@P8,"dok_WartMagR"=@P9,"dok_obiektgt"=@P10,"dok_WartOplRecykl"=@P11 WHERE "dok_Id"=@P12 AND "dok_WartUsNetto"=@P13 AND "dok_WartUsBrutto"=@P14 AND "dok_WartTwNetto"=@P15 AND "dok_WartTwBrutto"=@P16 AND "dok_WartOpZwr"=@P17 AND "dok_WartOpWyd"=@P18 AND "dok_WartMag"=@P19 AND "dok_WartMagP"=@P20 AND "dok_WartMagR"=@P21 AND "dok_obiektgt"=@P22 AND "dok_WartOplRecykl"=@P23',N'@P1 money,@P2 money,@P3 money,@P4 money,@P5 money,@P6 money,@P7 money,@P8 money,@P9 money,@P10 int,@P11 money,@P12 int,@P13 money,@P14 money,@P15 money,@P16 money,@P17 money,@P18 money,@P19 money,@P20 money,@P21 money,@P22 int,@P23 money',$0.0000,$0.0000,$810.0000,$874.8000,$0.0000,$0.0000,$0.0000,$0.0000,$0.0000,-8,$0.0000,95,$0.0000,$0.0000,$810.0000,$874.8000,$0.0000,$0.0000,$69.2700,$0.0000,$69.2700,-8,$0.0000


//rezerwacja stanu
//kontrahent dodawanie

declare @p3 int
set @p3=NULL
exec spIdentyfikator 'kh__Kontrahent',1,@p3 output
select @p3

declare @p3 int
set @p3=NULL
exec spIdentyfikator 'adr__Ewid',1,@p3 output
select @p3

declare @p3 int
set @p3=NULL
exec spIdentyfikator 'tel__Ewid',1,@p3 output
select @p3

declare @p3 int
set @p3=NULL
exec spIdentyfikator 'adr_Email',1,@p3 output
select @p3

exec sp_executesql N'INSERT INTO "test1".."adr__Ewid" ("adr_Id","adr_IdObiektu","adr_TypAdresu","adr_Nazwa","adr_NazwaPelna","adr_Telefon","adr_Faks","adr_Ulica","adr_NrDomu","adr_NrLokalu","adr_Kod","adr_Miejscowosc","adr_IdWojewodztwo","adr_IdPanstwo","adr_NIP","adr_Poczta","adr_Gmina","adr_Powiat","adr_Skrytka","adr_Symbol") VALUES (@P1,@P2,@P3,@P4,@P5,@P6,@P7,@P8,@P9,@P10,@P11,@P12,@P13,@P14,@P15,@P16,@P17,@P18,@P19,@P20)',N'@P1 int,@P2 int,@P3 int,@P4 varchar(17),@P5 varchar(17),@P6 varchar(9),@P7 varchar(1),@P8 varchar(14),@P9 varchar(1),@P10 varchar(1),@P11 varchar(6),@P12 varchar(15),@P13 int,@P14 int,@P15 varchar(10),@P16 varchar(1),@P17 varchar(1),@P18 varchar(1),@P19 varchar(1),@P20 varchar(17)',192,47,1,'Emilia Kompanoska','Emilia Kompanoska','605132634','','PLAC WOLNOŚCI,','','','87-865','IZBICA KUJAWSKA',1,1,'6661783539','','','','','emilia-kompanoska'

exec sp_executesql N'INSERT INTO "test1".."tel__Ewid" ("tel_Id","tel_IdAdresu","tel_Faks","tel_Nazwa","tel_Numer","tel_Podstawowy") VALUES (@P1,@P2,@P3,@P4,@P5,@P6)',N'@P1 int,@P2 int,@P3 bit,@P4 varchar(1),@P5 varchar(9),@P6 bit',49,192,0,'','605132634',1

exec sp_executesql N'INSERT INTO "test1".."adr__Ewid" ("adr_Id","adr_IdObiektu","adr_TypAdresu","adr_Nazwa","adr_NazwaPelna","adr_Telefon","adr_Faks","adr_Ulica","adr_NrDomu","adr_NrLokalu","adr_Kod","adr_Miejscowosc","adr_IdWojewodztwo","adr_IdPanstwo","adr_NIP","adr_Poczta","adr_Gmina","adr_Powiat","adr_Skrytka") VALUES (@P1,@P2,@P3,@P4,@P5,@P6,@P7,@P8,@P9,@P10,@P11,@P12,@P13,@P14,@P15,@P16,@P17,@P18,@P19)',N'@P1 int,@P2 int,@P3 int,@P4 varchar(1),@P5 varchar(1),@P6 varchar(1),@P7 varchar(1),@P8 varchar(1),@P9 varchar(1),@P10 varchar(1),@P11 varchar(1),@P12 varchar(1),@P13 int,@P14 int,@P15 varchar(1),@P16 varchar(1),@P17 varchar(1),@P18 varchar(1),@P19 varchar(1)',193,47,2,'','','','','','','','','',1,1,'','','','',''

exec sp_executesql N'INSERT INTO "test1".."adr__Ewid" ("adr_Id","adr_IdObiektu","adr_TypAdresu","adr_IdWojewodztwo","adr_IdPanstwo") VALUES (@P1,@P2,@P3,@P4,@P5)',N'@P1 int,@P2 int,@P3 int,@P4 int,@P5 int',194,47,11,1,1

exec sp_executesql N'INSERT INTO "test1".."adr_Email" ("am_Id","am_IdAdres","am_Email","am_Rodzaj","am_Podstawowy") VALUES (@P1,@P2,@P3,@P4,@P5)',N'@P1 int,@P2 int,@P3 varchar(24),@P4 int,@P5 bit',47,192,'emilia.kompanowska@o2.pl',1,1
//towar dodawanie

exec spSub_DokRezerwZwieksz 1,10,$3.0000
*/

}
catch (Exception $e) {
	//$logger->error($e->getMessage(), $e->getCode());
    exceptionDie($e);
}

exit();
?>
