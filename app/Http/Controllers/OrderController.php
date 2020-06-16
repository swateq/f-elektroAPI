<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function getTowarFromZk()
    {
        return DB::table('dok__Dokument')
            ->join('dok_Pozycja', 'dok__Dokument.dok_Id', '=', 'dok_Pozycja.ob_DokHanId')
            ->join('tw__Towar', 'tw__Towar.tw_Id', '=', 'dok_Pozycja.ob_TowId')
            ->join('kh__Kontrahent', 'kh__Kontrahent.kh_Id', '=', 'dok__Dokument.dok_PlatnikId')
            ->join('tw_CechaTw', 'tw_CechaTw.cht_IdTowar', '=', 'tw__Towar.tw_Id')
            ->select('tw_CechaTw.cht_IdCecha', 'tw__Towar.tw_Id','dok__Dokument.dok_NrPelny', 'dok__Dokument.dok_Id', 'dok__Dokument.dok_DataWyst','tw__Towar.tw_Symbol', 'tw__Towar.tw_Nazwa', 'tw__Towar.tw_Rodzaj', 'dok_Pozycja.ob_Ilosc', 'dok_Pozycja.ob_Id', 'kh__Kontrahent.kh_Symbol', 'dok__Dokument.dok_DataWyst')
            ->where('dok__Dokument.dok_Typ','=','16')
            ->where('tw_CechaTw.cht_IdCecha','4')
            ->where('dok__Dokument.dok_Status','!=','8')
            ->where('dok__Dokument.dok_KatId','!=','14')
            ->where('dok__Dokument.dok_DataWyst','>', now()->subDays(60))
            ->orderBy('dok__Dokument.dok_Id','desc')
            ->get();

    }

    public function getKomplet($id)
    {
        return DB::table('tw_Komplet')
            ->join('tw__Towar', 'tw__Towar.tw_Id', '=', 'tw_Komplet.kpl_IdSkladnik')
            ->select('tw__Towar.tw_Id', 'tw__Towar.tw_Symbol' ,'tw__Towar.tw_Nazwa' , 'tw__Towar.tw_JednMiary' ,'tw_Komplet.kpl_Liczba')
            ->where('tw_Komplet.kpl_IdKomplet','=',$id)
            ->get();
    }

    public function getTowar($id)
    {
        return DB::table('tw__Towar')
            ->join('tw_Stan','tw_Stan.st_TowId','=','tw__Towar.tw_Id')
            ->select('tw__Towar.*', 'tw_Stan.st_Stan')
            ->where('st_MagId','=','1')
            ->where('tw__Towar.tw_Id','=',$id)
            ->get();
    }

    public function getCena($id)
    {
        return DB::table('tw_Cena')
            ->where('tc_IdTowar',$id)
            ->first();
    }

    public function getDokument($id)
    {
        return DB::table('dok__Dokument')
            ->where('dok_Id',$id)
            ->first();
    }

    public function getPozycja($id)
    {
        return DB::table('dok_Pozycja')
            ->where('ob_DokMagId',$id)
            ->get();
    }

    public function getStan($id)
    {
        return DB::table('tw_Stan')
            ->select('st_Stan')
            ->where('tw_Stan.st_TowId',$id)
            ->where('st_MagId','=','1')
            ->get();
    }

    public function getMagRuch($prodId)
    {
        return DB::table('dok_MagRuch')
                ->where('mr_TowId',$prodId)
                ->where('mr_MagId', '1')
                ->where('mr_Pozostalo','!=','0.00')
                ->orderBy('mr_Data')
                ->get();
    }

    public function getLastId($db_name)
    {
        $item = DB::select( DB::raw(
            'declare @p3 int
            set @p3=null
            exec spIdentyfikator "'.$db_name.'",1,@p3 output
            select @p3 as id'));

        return $item[0]->id;
    }

    public function searchTowar(Request $request)
    {
        return DB::table('tw__Towar')
            ->select('tw__Towar.tw_Id', 'tw__Towar.tw_Symbol', 'tw__Towar.tw_Nazwa')
            ->whereRaw("tw__Towar.tw_Symbol like '%".$request->search."%'")
            ->OrWhereRaw("tw__Towar.tw_Nazwa like '%".$request->search."%'")
            ->get();
    }

    public function makeMagRuch($pozId, $towId, $quantity, $cena)
    {
        $id = $this->getLastId('dok_magruch');

        DB::table('dok_MagRuch')
            ->insert([
                'mr_Id' => $id,
                'mr_DoId' => NULL,
                'mr_SeriaId' => $id,
                'mr_PozId' => $pozId,
                'mr_TowId' => $towId,
                'mr_MagId' => 1,
                'mr_Data' => now(),
                'mr_Ilosc' => $quantity,
                'mr_Pozostalo' => $quantity,
                'mr_Cena' => $cena,
                'mr_Termin' => NULL,
            ]);

        DB::table('dok_MagWart')
            ->insert([
                'mw_SeriaId' => $id,
                'mw_PozId' => $pozId,
                'mw_Data' => now(),
                'mw_Cena' => $cena,
            ]);
    }

    public function connectPwRw($PwId, $RwId)
    {
        $pw = $this->getDokument($PwId);
        $rw =$this->getDokument($RwId);

        DB::table('dok__Dokument')
            ->where('dok_Id',$PwId)
            ->update([
                'dok_DoDokId' => $RwId,
                'dok_DoDokNrPelny' => $rw->dok_NrPelny
            ]);

        DB::table('dok__Dokument')
            ->where('dok_Id',$RwId)
            ->update([
                'dok_DoDokId' => $PwId,
                'dok_DoDokNrPelny' => $pw->dok_NrPelny
            ]);

        return redirect()->away('127.0.0.1:8000/order');
    }

    public function addPw($prodId, $quantity)
    {
        if($this->checkQuantity($prodId, $quantity))
        {
            $thisIdOfDokument = $this->getLastId('dok__Dokument');
            $now = now();

            $product = $this->getTowar($prodId)->first();
            $cena = $this->getCena($prodId);

            $netto = round($quantity) * $cena->tc_CenaNetto1;
            $brutto = round($quantity) * $cena->tc_CenaBrutto1;

            DB::table('tw_Stan')
                ->where('st_towID',$prodId)
                ->where('st_MagId', 1)
                ->update(['st_stan' => DB::raw('st_stan+'.$quantity)]);

            DB::table('dok__Dokument')
                ->insert([
                    'dok_Id' => $thisIdOfDokument,
                    'dok_MagId' => '1',
                    'dok_Podtyp' => '0',
                    'dok_DataWyst' => $now,
                    'dok_DataMag' => $now,
                    'dok_Typ' => 12,
                    'dok_WartNetto' => $netto,
                    'dok_WartBrutto' => $brutto,
                    'dok_WartTwNetto' => $netto,
                    'dok_WartTwBrutto' => $brutto,
                    'dok_WartMag' => $brutto,
                    'dok_WartMagP' => 0,
                    'dok_WartMagR' => $brutto,
                    'dok_KwWartosc' => $brutto,
                    'dok_JestRuchMag' => 1,
                    'dok_WalutaRodzajKursu' => 1,
                    'dok_CenyRodzajKursu' => 1,
                    'dok_WartVat' => 12.1300,
                    'dok_CenyPoziom' => -1,
                    'dok_CenyTyp' => 1,
                    'dok_CenyKurs' => 1,
                    'dok_DoDokDataWyst' => $now,
                    'dok_PlatTermin' => $now,
                    'dok_MscWyst' => 'Wadowice',
                    'dok_KwGotowka' => 0,
                    'dok_Waluta' => 'PLN',
                    'dok_Tytul' => 'Rozchód wewnętrzny',
                    'dok_Status' => 1,
                    'dok_ObiektGT' => -6,
                    'dok_TransakcjaId' => 0,
                    'dok_VenderoId' => 0,
                    'dok_SelloId' => 0,
                    'dok_ZaimportowanoDoEwidencjiAkcyzowej' => 0,
                    'dok_Wystawil' => 'Panel',
                    'dok_odebral' => 'Panel',
                    'dok_PersonelId' => 80
                ]);

            $lastIdOfPozycja = $this->getLastId('dok_Pozycja');

            DB::table('dok_Pozycja')
                    ->insert([
                        'ob_DokMagId' => $thisIdOfDokument,
                        'ob_TowId' => $prodId,
                        'ob_Id' => $lastIdOfPozycja,
                        'ob_DokHanLp' => 1,
                        'ob_DokMagLp' => 1,
                        'ob_Ilosc' => $quantity,
                        'ob_IloscMag' =>  $quantity,
                        'ob_Jm' => $product->tw_JednMiary,
                        'ob_CenaNetto' => $cena->tc_CenaNetto1,
                        'ob_CenaBrutto' => $cena->tc_CenaBrutto1,
                        'ob_CenaMag' => $cena->tc_CenaNetto1,
                        'ob_CenaWaluta' => $cena->tc_CenaNetto1,
                        'ob_WartMag' => $cena->tc_CenaNetto1,
                        'ob_WartVat' => $cena->tc_CenaNetto1,
                        'ob_WartNetto' => $cena->tc_CenaNetto1,
                        'ob_WartBrutto' => $cena->tc_CenaBrutto1,
                        'ob_VatId' => '100001',
                        'ob_VatProc' => '23.0000'
                    ]);

            DB::update( DB::raw('exec spSub_StanZwieksz 1,'.$prodId.',$'.$quantity.'.0000'));

            $this->makeMagRuch($lastIdOfPozycja,$prodId,$quantity,$cena->tc_CenaNetto1);

            $this->addRw($thisIdOfDokument, $prodId, $quantity);

            return redirect()->away('http://127.0.0.1:8000');
        }

        return false;
    }

    public function addRw($pwId, $prodId, $quantity)
    {
        $komplet = $this->getKomplet($prodId);
        $product = $this->getTowar($prodId)->first();
        $cena = $this->getCena($prodId);
        $now = now();

        $netto = round($quantity) * $cena->tc_CenaNetto1;
        $brutto = round($quantity) * $cena->tc_CenaBrutto1;
        $thisIdOfDokument = $this->getLastId('dok__Dokument');


        DB::table('tw_Stan')
            ->where('st_towID',$prodId)
            ->where('st_MagId', 1)
            ->update(['st_Stan' => DB::raw('st_Stan-'.$quantity)]);

        DB::table('dok__Dokument')
            ->insert([
                'dok_Id' => $thisIdOfDokument,
                'dok_MagId' => '1',
                'dok_Podtyp' => '0',
                'dok_DataWyst' => $now,
                'dok_DataMag' => $now,
                'dok_Typ' => 13,
                'dok_WartNetto' => $netto,
                'dok_WartBrutto' => $brutto,
                'dok_WartTwNetto' => $netto,
                'dok_WartTwBrutto' => $brutto,
                'dok_WartMag' => $brutto,
                'dok_WartMagP' => $brutto,
                'dok_KwWartosc' => $brutto,
                'dok_JestRuchMag' => 1,
                'dok_WalutaRodzajKursu' => 1,
                'dok_CenyRodzajKursu' => 1,
                'dok_WartVat' => 12.1300,
                'dok_CenyPoziom' => -1,
                'dok_CenyTyp' => 1,
                'dok_CenyKurs' => 1,
                'dok_DoDokDataWyst' => $now,
                'dok_PlatTermin' => $now,
                'dok_MscWyst' => 'Wadowice',
                'dok_KwGotowka' => 0,
                'dok_Waluta' => 'PLN',
                'dok_Tytul' => 'Przychód wewnętrzny',
                'dok_Status' => 1,
                'dok_ObiektGT' => -6,
                'dok_TransakcjaId' => 0,
                'dok_VenderoId' => 0,
                'dok_SelloId' => 0,
                'dok_ZaimportowanoDoEwidencjiAkcyzowej' => 0,
                'dok_Wystawil' => 'Panel',
                'dok_odebral' => 'Panel',
                'dok_PersonelId' => 80
            ]);

        $i = 1;
        foreach($komplet as $product)
        {
            $lastIdOfPozycja = $this->getLastId('dok_Pozycja');

            $cena = $this->getCena($product->tw_Id);

            DB::table('dok_Pozycja')
                ->insert([
                    'ob_DokMagId' => $thisIdOfDokument,
                    'ob_TowId' => $product->tw_Id,
                    'ob_Id' => $lastIdOfPozycja,
                    'ob_DokHanLp' => $i++,
                    'ob_DokMagLp' => 1,
                    'ob_Ilosc' => $quantity * $product->kpl_Liczba,
                    'ob_IloscMag' => $quantity * $product->kpl_Liczba,
                    'ob_Jm' => $product->tw_JednMiary,
                    'ob_CenaNetto' => $quantity * $cena->tc_CenaNetto1,
                    'ob_CenaBrutto' => $quantity * $cena->tc_CenaBrutto1,
                    'ob_CenaMag' => $quantity * $cena->tc_CenaNetto1,
                    'ob_CenaWaluta' => $quantity * $cena->tc_CenaNetto1,
                    'ob_WartMag' => $quantity * $cena->tc_CenaNetto1,
                    'ob_WartVat' => $quantity * $cena->tc_CenaNetto1,
                    'ob_WartNetto' => $quantity * $cena->tc_CenaNetto1,
                    'ob_WartBrutto' => $quantity * $cena->tc_CenaBrutto1,
                    'ob_VatId' => '100001',
                    'ob_VatProc' => '23.0000'
                ]);
        $this->makeMagRuch($lastIdOfPozycja,$product->tw_Id,$quantity,$cena->tc_CenaNetto1);

        DB::update( DB::raw('exec spSub_RuchDlaDyspozycji '.$lastIdOfPozycja.',"'.$now.'"'));
        DB::update( DB::raw('exec spSub_RuchDlaTowaru_pa 1,'.$product->tw_Id.',"'.$now.'"'));

        $magruch = $this->getMagRuch($product->tw_Id);

        $left = $quantity;
        foreach($magruch as $item)
        {
            if($item->mr_Pozostalo >= $quantity)
            {
                $tmp = $item->mr_Pozostalo - $quantity;
                DB::update( DB::raw('exec spSub_RuchZdejmuj '.$item->mr_Id.',$'.$tmp.',NULL'));
                break;
            }else{
                $left -= $item->mr_Pozostalo;
                DB::update( DB::raw('exec spSub_RuchZdejmuj '.$item->mr_Id.',$0.0000,NULL'));
            }
        }
    }

        $this->connectPwRw($thisIdOfDokument, $pwId);

    }

    public function checkQuantity($prodId, $quantity)
    {
        $komplet = $this->getKomplet($prodId);

        foreach($komplet as $product)
        {
            if(!((double)$this->getStan($product->tw_Id)->first()->st_Stan >= ($quantity * $product->kpl_Liczba)))
            {
              return false;
            }
        }
        return true;
    }
}
