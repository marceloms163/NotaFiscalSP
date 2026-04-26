<?php

namespace NotaFiscalSP\Helpers;

use Exception;
use Greenter\XMLSecLibs\Certificate\X509Certificate;
use Greenter\XMLSecLibs\Certificate\X509ContentType;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use NotaFiscalSP\Constants\FieldData\BooleanFields;
use NotaFiscalSP\Constants\Requests\RpsEnum;
use NotaFiscalSP\Constants\Requests\SimpleFieldsEnum;
use NotaFiscalSP\Entities\BaseInformation;

/**
 * Class Certificate
 * @package NotaFiscalSP\Helpers
 */
class Certificate
{
    /**
     * @param $path
     * @param $pass
     * @return string
     * @throws Exception
     */
    public static function pfx2pem($path, $pass)
    {
        // Get File
        $pfx = file_get_contents($path);

        // Export PEM
        $certificate = new X509Certificate($pfx, $pass);
        $pem = $certificate->export(X509ContentType::PEM);

        return $pem;
    }

    /**
     * @param $pem
     * @param $xml
     * @return string
     */
    public static function signXmlWithCertificate($pem, $xml)
    {
        // Create Signed XML
        $signer = new SignedXml();

        // Sign XML
        $signer->setCertificate($pem);
        $xmlSigned = $signer->signXml($xml);

        // Return XML File Signed
        return $xmlSigned;
    }


    public static function signItem(BaseInformation $baseInformation, $content)
    {
        $signatureValue = '';
        $pkeyId = openssl_get_privatekey($baseInformation->getCertificate());
        openssl_sign($content, $signatureValue, $pkeyId, OPENSSL_ALGO_SHA1);
        //openssl_free_key($pkeyId);
        return base64_encode($signatureValue);
    }

    public static function rpsSignatureString($params, int $version = 1)
    {
        // Versão 2 (Reforma Tributária 2026): IM com 12 posições; versão 1: 8 posições
        $imLength = $version >= 2 ? 12 : 8;
        $imPrestador = str_pad(substr(trim(General::onlyNumbers(General::getKey($params, SimpleFieldsEnum::IM_PROVIDER))), 0, $imLength), $imLength, '0', STR_PAD_LEFT);
        $serie = str_pad(substr(trim(General::getKey($params, SimpleFieldsEnum::RPS_SERIES)), 0, 5), 5, ' ', STR_PAD_RIGHT);
        $numero = str_pad(trim(General::onlyNumbers(General::getKey($params, SimpleFieldsEnum::RPS_NUMBER))), 12, '0', STR_PAD_LEFT);
        $data = str_replace('-', '', (string) General::getKey($params, RpsEnum::EMISSION_DATE));
        $tributacao = substr(General::getKey($params, RpsEnum::RPS_TAX), 0, 1);
        $status = substr(General::getKey($params, RpsEnum::RPS_STATUS), 0, 1);

        $issRetido = General::getKey($params, RpsEnum::ISS_RETENTION);
        $iss = ($issRetido === 'S' || $issRetido === 'true' || $issRetido === true || $issRetido == 1) ? 'S' : 'N';

        // Versão 2: usar ValorInicialCobrado ou ValorFinalCobrado (ValorServicos não existe na v2)
        if ($version >= 2) {
            $vServ = General::getKey($params, 'ValorInicialCobrado') ?: General::getKey($params, 'ValorFinalCobrado') ?: General::getKey($params, RpsEnum::SERVICE_VALUE);
        } else {
            $vServ = General::getKey($params, RpsEnum::SERVICE_VALUE) ?: General::getKey($params, 'ValorInicialCobrado') ?: General::getKey($params, 'ValorFinalCobrado');
        }
        $valorServicos = str_pad((int) round((float) $vServ * 100), 15, '0', STR_PAD_LEFT);
        $valorDeducoes = str_pad((int) round((float) General::getKey($params, RpsEnum::DEDUCTION_VALUE) * 100), 15, '0', STR_PAD_LEFT);
        $codigoServico = str_pad(General::onlyNumbers(General::getKey($params, RpsEnum::SERVICE_CODE)), 5, '0', STR_PAD_LEFT);

        $cpfCnpj = General::onlyNumbers(General::getKey($params, SimpleFieldsEnum::CNPJ) ?: General::getKey($params, SimpleFieldsEnum::CPF));
        $indicadorCpfCnpj = (strlen($cpfCnpj) == 11) ? '1' : (strlen($cpfCnpj) == 14 ? '2' : '3');
        $documento = str_pad($cpfCnpj, 14, '0', STR_PAD_LEFT);

        $string = $imPrestador . $serie . $numero . $data . $tributacao . $status . $iss . $valorServicos . $valorDeducoes . $codigoServico . $indicadorCpfCnpj . $documento;

        $expectedLength = $version >= 2 ? 90 : 86;
        if (strlen($string) !== $expectedLength) {
            throw new Exception("String de assinatura com tamanho inválido (" . strlen($string) . "): " . $string);
        }

        return $string;
    }

    public static function cancelSignatureString($params)
    {
        return sprintf('%08s', General::getKey($params, SimpleFieldsEnum::IM_PROVIDER)) .
            sprintf('%012s', General::getKey($params, SimpleFieldsEnum::NFE_NUMBER));
    }


    public static function nftsSignatureString($elements)
    {
        return '<tpNFTS>' . Certificate::makeXmlString($elements) . '</tpNFTS>';
    }

    public static function makeXmlString($array)
    {
        $string = '';
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $value = Certificate::makeXmlString($value);
            }
            $string .= '<' . $key . '>' . $value . '</' . $key . '>';
        }
        return $string;
    }

    public static function nftsCancellationSignatureString($elements)
    {
        return '<PedidoCancelamentoNFTSDetalheNFTS>' . Certificate::makeXmlString($elements) . '</PedidoCancelamentoNFTSDetalheNFTS>';
    }
}
