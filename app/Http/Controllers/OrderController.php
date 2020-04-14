<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
            ->orderBy('dok__Dokument.dok_Id','desc')
            ->take(100)
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

    public function searchTowar(Request $request)
    {
        return DB::table('tw__Towar')
            ->select('tw__Towar.tw_Id', 'tw__Towar.tw_Symbol', 'tw__Towar.tw_Nazwa')
            ->whereRaw("tw__Towar.tw_Symbol like '%".$request->search."%'")
            ->OrWhereRaw("tw__Towar.tw_Nazwa like '%".$request->search."%'")
            ->get();
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

        return redirect('127.0.0.1:8000/order');
    }

    public function addPw($prodId, $quantity)
    {
        if($this->checkQuantity($prodId, $quantity))
        {
            $lastIdOfDokument = DB::table('dok__Dokument')
                        ->select('dok_Id')
                        ->orderBy('dok_Id','desc')
                        ->first();
            $thisIdOfDokument = $lastIdOfDokument->dok_Id+1;
            $now = now();

            $product = $this->getTowar($prodId)->first();
            $cena = $this->getCena($prodId);

            $netto = round($quantity) * $cena->tc_CenaNetto1;
            $brutto = round($quantity) * $cena->tc_CenaBrutto1;

            DB::table('tw_Stan')
                ->where('st_towID',$prodId)
                ->where('st_MagId', 1)
                ->update(['st_stan' => DB::raw('st_stan+'.$quantity)]);

            $id = DB::table('dok__Dokument')
                ->insertGetId([
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

            $lastIdOfPozycja = DB::table('dok_Pozycja')
                    ->select('ob_Id')
                    ->orderBy('ob_Id','desc')
                    ->first();

            DB::table('dok_Pozycja')
                    ->insert([
                        'ob_DokMagId' => $thisIdOfDokument,
                        'ob_TowId' => $prodId,
                        'ob_Id' => $lastIdOfPozycja->ob_Id+1,
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

            $this->addRw($thisIdOfDokument, $prodId, $quantity);

            return $thisIdOfDokument;
        }

        return false;
    }

    public function addRw($dokId, $prodId, $quantity)
    {
        $komplet = $this->getKomplet($prodId);
        $product = $this->getTowar($prodId)->first();
        $cena = $this->getCena($prodId);
        $now = now();

        $netto = round($quantity) * $cena->tc_CenaNetto1;
        $brutto = round($quantity) * $cena->tc_CenaBrutto1;


        DB::table('tw_Stan')
            ->where('st_towID',$prodId)
            ->where('st_MagId', 1)
            ->update(['st_stan' => DB::raw('st_stan-'.$quantity)]);

        $id = DB::table('dok__Dokument')
            ->insertGetId([
                'dok_Id' => ++$dokId,
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
            $lastIdOfPozycja = DB::table('dok_Pozycja')
                ->select('ob_Id')
                ->orderBy('ob_Id','desc')
                ->first();

            $cena = $this->getCena($product->tw_Id);

            DB::table('dok_Pozycja')
                ->insert([
                    'ob_DokMagId' => $dokId,
                    'ob_TowId' => $product->tw_Id,
                    'ob_Id' => $lastIdOfPozycja->ob_Id+1,
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
        }

        $this->connectPwRw($dokId, --$dokId);

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
