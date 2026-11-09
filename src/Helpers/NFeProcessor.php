<?php
namespace Helpers;

class NFeProcessor {
    
    /**
     * Extrai os dados essenciais de um arquivo XML de NF-e para a etapa de verificação.
     *
     * @param string $xmlFilePath Caminho para o arquivo XML.
     * @return array|null Retorna um array estruturado com os dados ou null em caso de erro.
     */
    public function extractDataForVerification(string $xmlFilePath): ?array
    {
        if (!file_exists($xmlFilePath)) {
            error_log("Arquivo XML não encontrado em: $xmlFilePath");
            return null;
        }

        // Carrega o XML, tratando os namespaces (essencial para NF-e)
        $xmlContent = file_get_contents($xmlFilePath);
        // Remove quebras de linha e outros caracteres que podem invalidar o XML
        $xmlContent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $xmlContent);
        $xml = simplexml_load_string($xmlContent);

        if ($xml === false) {
            error_log("Falha ao carregar o XML: $xmlFilePath");
            return null;
        }

        // Namespace padrão da NF-e
        $ns = 'http://www.portalfiscal.inf.br/nfe';

        // Acessando os nós principais
        $infNFe = $xml->NFe->infNFe;
        if (!$infNFe) {
            return null; // Estrutura básica não encontrada
        }

        // 1. Extrair dados do Fornecedor (Emitente)
        $emit = $infNFe->children($ns)->emit;
        $fornecedor = [
            'cnpj' => (string) $emit->CNPJ,
            'razao_social' => (string) $emit->xNome
        ];

        $address = $emit->enderEmit;
        $optional = [
            'nome_fantasia' => (string) $emit->xFant,
            'email' => (string) $emit->email,
            'telefone' => (string) $address->fone,
            'endereco' => implode(', ', array_filter([(string) $address->xLgr, (string) $address->nro, (string) $address->xCpl, (string) $address->xBairro])),
            'cidade' => (string) $address->xMun, 'estado' => (string) $address->UF, 'cep' => (string) $address->CEP
        ];
        foreach ($optional as $field => $value) {
            if (trim($value) !== '') $fornecedor[$field] = trim($value);
        }

        // 2. Extrair dados da Nota Fiscal
        $ide = $infNFe->children($ns)->ide;
        $total = $infNFe->children($ns)->total->ICMSTot;
        $notaFiscal = [
            'chave_acesso' => str_replace('NFe', '', (string) $infNFe['Id']),
            'numero' => (string) $ide->nNF,
            'data_emissao' => (string) $ide->dhEmi,
            'valor_total' => (string) $total->vNF
        ];

        // 3. Extrair lista de produtos
        $produtosXml = [];
        foreach ($infNFe->children($ns)->det as $item) {
            $prod = $item->prod;
            $produtosXml[] = [
                'codigo_xml' => (string) $prod->cProd,
                'nome_xml' => (string) $prod->xProd,
                'quantidade' => (float) $prod->qCom,
                'valor_unitario' => (float) $prod->vUnCom,
                'unidade' => (string) $prod->uCom
            ];
        }

        return [
            'fornecedor' => $fornecedor,
            'nota_fiscal' => $notaFiscal,
            'produtos_xml' => $produtosXml
        ];
    }
}