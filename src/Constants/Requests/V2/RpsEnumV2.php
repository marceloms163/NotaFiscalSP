<?php

namespace NotaFiscalSP\Constants\Requests\V2;

use NotaFiscalSP\Constants\Requests\RpsEnum;

class RpsEnumV2
{
    const VALUE_INITIAL_CHARGED = 'ValorInicialCobrado';
    const VALUE_FINAL_CHARGED = 'ValorFinalCobrado';
    const VALUE_FINE = 'ValorMulta';
    const VALUE_INTEREST = 'ValorJuros';
    const VALUE_IPI = 'ValorIPI';
    const EXIGIBILITY_SUSPENDED = 'ExigibilidadeSuspensa';
    const ADVANCE_INSTALLMENT_PAYMENT = 'PagamentoParceladoAntecipado';
    const NCM = 'NCM';
    const NBS = 'NBS';
    const EVENT_ACTIVITY = 'atvEvento';
    const SERVICE_LOCATION = 'cLocPrestacao';
    const SERVICE_COUNTRY = 'cPaisPrestacao';
    const IBSCBS = 'IBSCBS';

    public static function extraTypes()
    {
        return [
            RpsEnum::TOTAL_VALUE,
            RpsEnumV2::VALUE_INITIAL_CHARGED,
            RpsEnumV2::VALUE_FINAL_CHARGED,
            RpsEnumV2::VALUE_FINE,
            RpsEnumV2::VALUE_INTEREST,
            RpsEnumV2::VALUE_IPI,
            RpsEnumV2::EXIGIBILITY_SUSPENDED,
            RpsEnumV2::ADVANCE_INSTALLMENT_PAYMENT,
            RpsEnumV2::NCM,
            RpsEnumV2::NBS,
            RpsEnumV2::EVENT_ACTIVITY,
            RpsEnumV2::SERVICE_LOCATION,
            RpsEnumV2::SERVICE_COUNTRY,
            RpsEnumV2::IBSCBS,
        ];
    }
}
