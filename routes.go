package main

import (
	"github.com/gin-contrib/gzip"
	"github.com/gin-contrib/sessions"
	"github.com/gin-contrib/sessions/cookie"
	"github.com/gin-gonic/gin"
	"github.com/spf13/viper"
	"net/http"
	"strings"
)

var noHandle404 = []string{"/api/"}
var webRoot string

func ConfigureRoutes() *gin.Engine {
	e := gin.Default()

	viper.SetDefault("session.secret", "changeme")
	viper.SetDefault("session.name", "panelsession")

	webRoot = viper.GetString("web.root")

	store := cookie.NewStore([]byte(viper.GetString("session.secret")))
	e.Use(sessions.Sessions(viper.GetString("session.name"), store))

	e.Handle("GET", "/api/factoid", getFactoids)
	e.Handle("GET", "/api/factoid/*name", getFactoid)
	e.Handle("PUT", "/api/factoid/*name", authorized("factoid.manage"), updateFactoid)
	e.Handle("DELETE", "/api/factoid/*name", authorized("factoid.manage"), deleteFactoid)

	e.Handle("GET", "/login", login)
	e.Handle("GET", "/login-callback", loginCallback)

	css := e.Group("/css")
	{
		css.Use(gzip.Gzip(gzip.DefaultCompression))
		css.StaticFS("", http.Dir(webRoot+"/css"))
	}
	fonts := e.Group("/fonts")
	{
		fonts.Use(gzip.Gzip(gzip.DefaultCompression))
		fonts.StaticFS("", http.Dir(webRoot+"/fonts"))
	}
	img := e.Group("/img")
	{
		img.StaticFS("", http.Dir(webRoot+"/img"))
	}
	js := e.Group("/js", setContentType("application/javascript"))
	{
		js.Use(gzip.Gzip(gzip.DefaultCompression))
		js.StaticFS("", http.Dir(webRoot+"/js"))
	}
	e.StaticFile("/favicon.png", webRoot+"/favicon.png")
	e.StaticFile("/favicon.ico", webRoot+"/favicon.ico")
	e.NoRoute(handle404)

	return e
}

func authorized(perm string) gin.HandlerFunc {
	return func(c *gin.Context) {
		session := sessions.Default(c)
		discordId, ok := session.Get("discordId").(string)
		if !ok || discordId == ""{
			c.AbortWithStatus(http.StatusUnauthorized)
			return
		}
		permission := &Permission{
			DiscordId:  discordId,
			Permission: perm,
		}

		exists := int64(0)
		err := Database.Model(permission).Where(permission).Count(&exists).Error
		if err != nil {
			c.AbortWithStatusJSON(http.StatusInternalServerError, Error{Message: err.Error()})
			return
		}
		if exists > 1 {
			c.AbortWithStatus(http.StatusUnauthorized)
			return
		}
		c.Next()
	}
}

func handle404(c *gin.Context) {
	for _, v := range noHandle404 {
		if strings.HasPrefix(c.Request.URL.Path, v) {
			c.AbortWithStatus(http.StatusNotFound)
			return
		}
	}

	if strings.HasSuffix(c.Request.URL.Path, ".js") {
		c.Header("Content-Type", "application/javascript")
		c.File(webRoot + c.Request.URL.Path)
		return
	}

	if strings.HasSuffix(c.Request.URL.Path, ".json") {
		c.Header("Content-Type", "application/json")
		c.File(webRoot + c.Request.URL.Path)
		return
	}

	if strings.HasSuffix(c.Request.URL.Path, ".css") {
		c.Header("Content-Type", "text/css")
		c.File(webRoot + c.Request.URL.Path)
		return
	}

	if strings.HasSuffix(c.Request.URL.Path, ".tar") {
		c.Header("Content-Type", "application/x-tar")
		c.File(webRoot + c.Request.URL.Path)
		return
	}

	c.File(webRoot + "/index.html")
}

func setContentType(contentType string) gin.HandlerFunc {
	return func(c *gin.Context) {
		c.Header("Content-Type", contentType)
		c.Next()
	}
}