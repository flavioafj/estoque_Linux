<?php
namespace Models;
use PDO;

class ItensNota extends BaseModel { // Assumindo BaseModel com PDO connection
    public function getByNomeXml($nomeXml) {
        $stmt = $this->db->prepare("SELECT produto_id FROM itens_nota WHERE UPPER(nome_xml) = UPPER(:nome_xml)");
        $stmt->execute(['nome_xml' => $nomeXml]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Método para insert (usado em registrar_entrada_nfe.php)
    public function insert($nomeXml, $produtoId) {
        $stmt = $this->db->prepare("INSERT INTO itens_nota (nome_xml, produto_id) VALUES (:nome_xml, :produto_id)");
        return $stmt->execute(['nome_xml' => $nomeXml, 'produto_id' => $produtoId]);
    }
}