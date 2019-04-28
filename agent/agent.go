package main

import (
	"./config"
	"./protocol"
	"./protocol/cgi"
	"encoding/json"
	"fmt"
	"io"
	"io/ioutil"
	"log"
	"net"
	"net/url"
	"os"
	"strings"
	"time"
)

var (
	Info    *log.Logger
	Warning *log.Logger
	Error   *log.Logger
)

func init() {
	errFile, err := os.OpenFile(config.ServerLog, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0666)
	if err != nil {
		log.Fatalln("打开日志文件失败：", err)
	}

	Info = log.New(io.MultiWriter(os.Stderr, errFile), "Info:", log.Ldate|log.Ltime|log.Lshortfile)
	Warning = log.New(io.MultiWriter(os.Stderr, errFile), "Warning:", log.Ldate|log.Ltime|log.Lshortfile)
	Error = log.New(io.MultiWriter(os.Stderr, errFile), "Error:", log.Ldate|log.Ltime|log.Lshortfile)

}

func main() {
	addr := config.ServerHost + ":" + config.ServerPort
	conn, err := net.Dial("tcp", addr)
	if err != nil {
		Error.Println("Server not found.")
	} else {
		defer conn.Close()
		Info.Println("Connection OK.")
		Info.Println(conn.RemoteAddr().String())
		Info.Println(conn.LocalAddr())
		// go doMsgHandler(conn)
	}

	tmpBuffer := make([]byte, 0)
	readerChannel := make(chan []byte, 100)
	msg := make([]byte, 1024)
	go readerMsg(readerChannel, conn)
	go HeartBeating(conn)
	for {
		// fmt.Printf("Enter a sentence:")
		// fmt.Scanf("%s\r\n", &str)
		// in, err := conn.Write(protocol.Packet([]byte(fmt.Sprintf("%s\r\n", str))))
		// if err != nil {
		// 	Error.Println("Error when send to server: %d\n", in)
		// }
		length, err := conn.Read(msg)
		if err != nil {
			if err == io.EOF {
				//go reLink()
			} else {
				Error.Println("Error when read from server.err:", err)
			}
		}
		tmpBuffer = protocol.Unpack(append(tmpBuffer, msg[:length]...), readerChannel)
	}

}

func HeartBeating(conn net.Conn) {
	for {
		_, err := conn.Write(protocol.Packet([]byte(fmt.Sprintf("%s\r\n", "HeartBeating")))) //一个字母和一个空格都占一个byte的长度
		if err != nil {
			Error.Println("client send failed; err:", err)
		}
		time.Sleep(config.HeartBeat * time.Second)
	}
}

func readerMsg(readerChannel chan []byte, conn net.Conn) {
	for {
		select {
		case data := <-readerChannel:
			str := strings.TrimSpace(string(data))
			switch {
			case str == "HeartBeating":
			case str == "del":
				Info.Println("recive Msg:", str)
				_, err := conn.Write(protocol.Packet([]byte(fmt.Sprintf("%s\r\n", "yacKey")))) //一个字母和一个空格都占一个byte的长度
				if err != nil {
					Error.Println("client send failed; err:", err)
				}
			case strings.Contains(str, "yacKey"):
				Info.Println("recive Msg:", str)
				jsonStr := make(map[string]interface{})
				json.Unmarshal([]byte(str), &jsonStr)
				env := make(map[string]string)
				env["SCRIPT_FILENAME"] = config.CgiScriptFile
				go SendCgi(env, url.Values{"yacKey": {jsonStr["yacKey"].(string)}})
			default:
				Info.Println("recive Msg:", str)
			}
		}
	}
}

func SendCgi(env map[string]string, param url.Values) {
	content := make([]byte, 1024)
	addrs := config.CgiServerHost + ":" + config.CgiServerPort
	fcgi, err := fcgiclient.Dial("tcp", addrs)
	if err != nil {
		Error.Println("cgi 连接失败！error：", err)
	}

	resp, err := fcgi.PostForm(env, param)
	if err != nil {
		Error.Println("cgi post失败！error：", err)
	}

	content, err = ioutil.ReadAll(resp.Body)
	if err != nil {
		Error.Println("cgi 读取数据失败！error：", err)
	}
	Info.Println("cgi返回内容为：", string(content))
}
