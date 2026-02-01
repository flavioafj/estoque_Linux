<?php
$fallbackImage = '/assets/images/fallback.jpg';
?>
<div class="product-card">  
    <img src="<?php echo (!empty($produto['foto_url']) && file_exists(PUBLIC_PATH . '/' . ltrim($produto['foto_url'], '/'))) ? htmlspecialchars($produto['foto_url']) : htmlspecialchars($fallbackImage); ?>" alt="<?php echo htmlspecialchars($produto['nome']); ?>">  
    <h3><?php echo htmlspecialchars($produto['nome']); ?></h3>  
    <p>Estoque: <?php echo htmlspecialchars($produto['estoque_atual']); ?></p>  
    <input type="number" id="qty-<?php echo $produto['id']; ?>" step="1" value="0" min="0">  
    <button class="btn btn-pegar" data-produto-id="<?php echo $produto['id']; ?>">Pegar</button>  
    <button class="btn btn-add-cart" data-produto-id="<?php echo $produto['id']; ?>">Adicionar ao Carrinho</button>  
</div>