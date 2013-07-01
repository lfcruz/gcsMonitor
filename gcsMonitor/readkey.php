<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */
function readkey()
{
   system("stty -icanon");
   fread(STDIN, 1);
}
?>
