CHANGELOG
=========

+ v0.1.2
修复了事件循环中出错导致事件循环异常的问题
优化了Libevent事件监听器
优化了事件容器
优化了Server/Worker/Client关系和功能
添加了Client write buffer功能
添加了延迟事件和Client延迟关闭功能(下一个事件循环)
添加了一些HTTP错误异常类

+ v0.1.0
支持简单的解析HTTP协议, 但不能针对请求进行自动的处理和响应. 优化了组件和类结构, 有一个比较稳定的守护进程和服务模型,
但对响应的构造和发送支持较差. 支持HTTP持久连接, 以管道模式编写请求的处理逻辑.