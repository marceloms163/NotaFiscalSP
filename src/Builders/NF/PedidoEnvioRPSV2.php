<?php

namespace NotaFiscalSP\Builders\NF;

use NotaFiscalSP\Builders\NfAbstractV2;
use NotaFiscalSP\Constants\Methods\NfMethods;
use NotaFiscalSP\Constants\Requests\HeaderEnum;
use NotaFiscalSP\Entities\BaseInformation;
use NotaFiscalSP\Helpers\Xml;
use NotaFiscalSP\Validators\RpsValidator;

class PedidoEnvioRPSV2 extends NfAbstractV2
{

    public function makeXmlRequest(BaseInformation $information, $rps)
    {
        $documents = RpsValidator::validateRps($information, $rps);
        $header = $this->makeHeader($information, [
            HeaderEnum::CPFCNPJ_SENDER => true
        ]);
        $allRps = $this->makeRPS($information, $documents);

        $request = array_merge($header, $allRps);

        return Xml::makeRequestXML(NfMethods::ENVIO, $request);
    }

}
