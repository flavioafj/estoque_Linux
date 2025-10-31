<?php  
$fallbackImage = '/assets/images/fallback.jpg';  
?>  
<div class="product-card">  
    <img src="<?php echo (!empty($produto['foto_url']) && file_exists(PUBLIC_PATH . '/' . ltrim($produto['foto_url'], '/'))) ? htmlspecialchars($produto['foto_url']) : htmlspecialchars($fallbackImage); ?>" alt="<?php echo htmlspecialchars($produto['nome']); ?>">  
    <h3><?php echo htmlspecialchars($produto['nome']); ?></h3>  
    <p>Estoque Atual: <?php echo htmlspecialchars($produto['estoque_atual']); ?></p>  
    <input type="number" name="quantidade[<?php echo $produto['id']; ?>]" step="1.0" min="0" placeholder="Quantidade apurada">  
</div>  