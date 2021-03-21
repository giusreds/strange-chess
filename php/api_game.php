<?php
session_start();
header("Content-type: application/json");
include("./mysql.php");
include("./rules.php");
include("./match.php");

if (
    isset($_POST["id"]) && isset($_POST["action"])
    && isset($_SESSION["user"])
) {
    // Parametri sempre presenti
    $action = $_POST["action"];
    $match_id = $_POST["id"];
    $user_id = $_SESSION["user_id"];

    $found = 0;
    checkTimeExceeded($match_id);

    $query = "SELECT * FROM `match_team` WHERE `match_id` = '{$match_id}'";
    $result = $mysqli->query($query);
    // Se la partita non esiste
    if (!$result) return false;
    while ($row = $result->fetch_array()) {
        if ($row["user"] == $user_id) {
            $player_id = $row["team"];
            $found = 1;
        }
    }
    if (!$found) exit;
    $result = getLastMove($match_id);

    $number = $result["number"];

    // Valuto l'azione da eseguire
    switch ($action) {
        case "refresh":
            // Resetto l'impronta, cosi da forzare il refresh
            $_SESSION[$match_id] = NULL;
        case "pull":
            $match_status = fetchMatch($result, $player_id);
            clean($match_status["chessboard"]);
            $match_status["time"] = getTime($result["timestamp"]);
            $match_status["changed"] = 1;
            // Se l'impronta e'uguale all'ultima registrata, ritorno
            // soltanto {"r":0} per indicare di non eseguire il refresh
            if (
                isset($_SESSION[$match_id]) &&
                $_SESSION[$match_id] == md5(json_encode($match_status))
            ) {
                $risp["changed"] = 0;
                echo json_encode($risp);
                exit;
            }
            // Altrimenti, faccio full-refresh e aggiorno l'impronta
            echo json_encode($match_status);
            $_SESSION[$match_id] = md5(json_encode($match_status));
            exit;
        case "check":
            if (!isset($_POST["source"])) exit;
            //include_once("./rules.php");
            $match_status = fetchMatch($result, $player_id);
            $allowed = allowed($match_status["chessboard"], $_POST["source"]);
            echo json_encode($allowed);
            exit;
        case "move":
            if (!isset($_POST["source"]) || !isset($_POST["destination"])) exit;
            //include_once("./rules.php");
            $match_status = fetchMatch($result, $player_id);
            $allowed = allowed($match_status["chessboard"], $_POST["source"]);
            // Verifico fattibilità nella matrice personalizzata
            // e verifico che sia il mio turno
            if (in_array($_POST["destination"], $allowed) && $match_status["yourturn"]) {

                // Scacchiera e array di pedine mangiate
                $chessboard = json_decode($result["chessboard"]);
                $captured = json_decode($result["captured"]);

                // Calcolo gli indici sulla matrice originale
                $x_s = index(cellIndex($_POST["source"])[0], $player_id);
                $y_s = index(cellIndex($_POST["source"])[1], $player_id);
                $x_d = index(cellIndex($_POST["destination"])[0], $player_id);
                $y_d = index(cellIndex($_POST["destination"])[1], $player_id);
                $source = cellName($x_s, $y_s);
                $destination = cellName($x_d, $y_d);
                $promotion = (isset($_POST["promotion"])) ? $_POST["promotion"] : NULL;
                $aftermove = move($chessboard, $captured, $source, $destination, 0, $promotion);
                $chessboard = json_encode($aftermove[0]);
                $captured = json_encode($aftermove[1]);

                // temporaneo
                $number++;
                $turn = ($player_id + 1) % 2;
                // Aggiorno nel DataBase
                $query = "INSERT INTO `match_log` (`id`, `number`, `turn`, `chessboard`,`captured`, `timestamp`)
                VALUES ('{$match_id}', '{$number}', '{$turn}', '{$chessboard}', '{$captured}', CURRENT_TIMESTAMP)";
                $mysqli->query($query);

                // Se tutto ok
                // ridondante
                $match_status = fetchMatch($result, $player_id);
                clean($match_status["chessboard"]);
                // Se l'impronta e'uguale all'ultima registrata, ritorno
                // soltanto {"r":0} per indicare di non eseguire il refresh
                if (isset($_SESSION[$match_id]) && $_SESSION[$match_id] == md5(json_encode($match_status))) {
                    $risp["changed"] = 0;
                    echo json_encode($risp);
                    exit;
                }
                // Altrimenti, faccio full-refresh e aggiorno l'impronta
                echo json_encode($match_status);
                $_SESSION[$match_id] = md5(json_encode($match_status));
            }
            exit;
    }
}