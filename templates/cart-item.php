<tr>  
    <td><?php echo htmlspecialchars($item['produto']['nome']); ?></td>  
    <td>  
        <form method="POST">  
            <input type="hidden" name="action" value="update">  
            <input type="hidden" name="produto_id" value="<?php echo $item['produto']['id']; ?>">  
            <input type="number" name="quantidade" value="<?php echo $item['quantidade']; ?>" step="1" min="0">  
            <button type="submit">Atualizar</button>  
        </form>  
    </td>  
    <td>  
        <form method="POST">  
            <input type="hidden" name="action" value="remove">  
            <input type="hidden" name="produto_id" value="<?php echo $item['produto']['id']; ?>">  
            <button type="submit">Remover</button>  
        </form>  
    </td>  
</tr>  