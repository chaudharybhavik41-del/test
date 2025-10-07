
<?php
declare(strict_types=1);

namespace Coupler;

final class Expression
{
    /** Evaluate a tiny DSL of assignment lines and return symbol table.
     *  Allowed: + - * / ( ) , variables [a-zA-Z_][a-zA-Z0-9_]*, numbers, and functions: round, floor, ceil, min, max.
     *  Example:
     *    area_m2 = (L_mm/1000) * (W_mm/1000);
     *    acc_qty = round(area_m2 * (Thk_mm/1000) * density, 3);
     */
    public static function evaluate(string $script, array $ctx): array
    {
        $symbols = $ctx;
        $lines = preg_split('/[;\n\r]+/', $script, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(.+)$/', $line, $m)) {
                throw new \RuntimeException("Invalid line: $line");
            }
            $var = $m[1];
            $expr = $m[2];
            $val = self::evalExpr($expr, $symbols);
            $symbols[$var] = $val;
        }
        return $symbols;
    }

    private static function evalExpr(string $expr, array $vars): float
    {
        // Tokenize
        $tokens = [];
        $i = 0; $len = strlen($expr);
        while ($i < $len) {
            $ch = $expr[$i];
            if (ctype_space($ch)) { $i++; continue; }
            if (ctype_alpha($ch) || $ch === '_') {
                $j = $i+1;
                while ($j < $len && (ctype_alnum($expr[$j]) || $expr[$j] === '_')) $j++;
                $tokens[] = ['type'=>'id', 'val'=>substr($expr, $i, $j-$i)];
                $i = $j; continue;
            }
            if (ctype_digit($ch) or $ch=='.') {
                $j = $i+1;
                while ($j < $len && (ctype_digit($expr[$j]) || $expr[$j]=='.')) $j++;
                $tokens[] = ['type'=>'num', 'val'=>floatval(substr($expr, $i, $j-$i))];
                $i = $j; continue;
            }
            if ($ch in ['+','-','*','/','(',')',',']) {
                $tokens[] = ['type'=>$ch, 'val'=>$ch];
                $i++; continue;
            }
            throw new \RuntimeException("Bad character in expression: ".$ch);
        }

        // Shunting-yard to RPN
        $prec = ['+'=>1,'-'=>1,'*'=>2,'/'=>2];
        $out = []; $ops = [];
        for ($k=0; $k<count($tokens); $k++) {
            $t = $tokens[$k];
            if ($t['type']==='num') $out[] = $t;
            elseif ($t['type']==='id') {
                // Could be function or variable
                $next = $tokens[$k+1]['type'] ?? null;
                if ($next==='(') { $ops[] = $t; } // function marker
                else {
                    $name = $t['val'];
                    $val = $vars[$name] ?? 0.0;
                    $out[] = ['type'=>'num','val'=>floatval($val)];
                }
            }
            elseif ($t['type']==='(') $ops[] = $t;
            elseif ($t['type']===')') {
                while (!empty($ops) && end($ops)['type']!=='(') $out[] = array_pop($ops);
                if (empty($ops)) throw new \RuntimeException("Mismatched parenthesis");
                array_pop($ops); // pop '('
                // If function on stack, pop it to output
                if (!empty($ops) && end($ops)['type']==='id') $out[] = array_pop($ops);
            }
            elseif (isset($prec[$t['type']])) {
                while (!empty($ops) && isset($prec[end($ops)['type']]) && $prec[end($ops)['type']] >= $prec[$t['type']]) {
                    $out[] = array_pop($ops);
                }
                $ops[] = $t;
            }
            elseif ($t['type']===',') {
                while (!empty($ops) && end($ops)['type']!=='(') $out[] = array_pop($ops);
            }
        }
        while (!empty($ops)) {
            $op = array_pop($ops);
            if ($op['type']==='(') throw new \RuntimeException("Mismatched parenthesis");
            $out[] = $op;
        }

        // Evaluate RPN
        $stack = [];
        foreach ($out as $t) {
            if ($t['type']==='num') { $stack[] = $t['val']; continue; }
            $op = $t['type'];
            if ($op==='+' or $op==='-' or $op==='*' or $op==='/') {
                if (count($stack) < 2) throw new \RuntimeException("Arity error");
                $b = array_pop($stack); $a = array_pop($stack);
                switch ($op) {
                    case '+': $stack[] = $a + $b; break;
                    case '-': $stack[] = $a - $b; break;
                    case '*': $stack[] = $a * $b; break;
                    case '/': $stack[] = $b==0.0 ? 0.0 : $a / $b; break;
                }
                continue;
            }
            if ($op==='id') {
                // functions
                $fname = $t['val'];
                if ($fname==='round') {
                    $dec = 0; $x = array_pop($stack); 
                    if (!empty($stack)) { $dec = intval(array_pop($stack)); }
                    $stack[] = round($x, $dec);
                } elseif ($fname==='floor') {
                    $x = array_pop($stack); $stack[] = floor($x);
                } elseif ($fname==='ceil') {
                    $x = array_pop($stack); $stack[] = ceil($x);
                } elseif ($fname==='min') {
                    $b = array_pop($stack); $a = array_pop($stack); $stack[] = min($a,$b);
                } elseif ($fname==='max') {
                    $b = array_pop($stack); $a = array_pop($stack); $stack[] = max($a,$b);
                } else {
                    throw new \RuntimeException("Unknown function: ".$fname);
                }
                continue;
            }
            throw new \RuntimeException("Unknown token in RPN");
        }
        if (count($stack)!=1) throw new \RuntimeException("Evaluation error");
        return floatval($stack[0]);
    }
}
