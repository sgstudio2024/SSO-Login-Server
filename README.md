这是一个支持跨域的SSO单点登录的母系统客户端，采用了纯php设计
## 上传源码到服务器
将所有php源码上传后你需要

1.修改sso_authorize.php，login.php，logout.php中的sgstudio2025.xyz 将这个域名换成你自己的
2.修改send_verification.php 将里面的stmp设置替换成你自己的
3.导入默认数据库userdb.sql.zip
4.运行/create_clients_helper.php 根据提示注册一个客户端
5.注册客户端之后一定删除create_clients_helper.php否则将出现安全问题


## SSO单点登录系统子系统请看我主页另一个项目
