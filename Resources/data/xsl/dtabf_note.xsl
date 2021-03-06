<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
  xmlns:tei="http://www.tei-c.org/ns/1.0"
  exclude-result-prefixes="tei"
  version="2.0">

<xsl:import href="dta-tools/dta-base.xsl"/>
<xsl:import href="dtabf_customize.xsl"/>

<xsl:output method="html" />

<xsl:template match="tei:body">
    <xsl:if test="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:notesStmt/tei:note">
        <div class="source-description">
            <xsl:apply-templates select="/tei:TEI/tei:teiHeader/tei:fileDesc/tei:notesStmt/tei:note/node()"/>
        </div>
    </xsl:if>
</xsl:template>

<xsl:template match='tei:note[@type="editorial"]'>
  <xsl:choose>
    <xsl:when test="@place='foot'"><a class="editorial-marker img-info-sign" href="#{generate-id()}">&#160;</a><span id="{generate-id()}" class="editorial foot"><xsl:apply-templates/></span>
    </xsl:when>
    <xsl:otherwise>
      <span class="editorial inline"><xsl:apply-templates/></span>
    </xsl:otherwise>
  </xsl:choose>
</xsl:template>

<xsl:template match='tei:ref'>
  <xsl:choose>
    <xsl:when test="@target">
      <xsl:choose>
        <xsl:when test="@type = 'editorialNote'">
          <a class="hoverTooltip glossary" href="#">
            <xsl:attribute name="data-title"><xsl:value-of select="substring(@target, 2)" /></xsl:attribute>
            <xsl:apply-templates/>
          </a>
        </xsl:when>
        <xsl:otherwise>
          <a class="external">
            <xsl:attribute name="href"><xsl:value-of select="@target" /></xsl:attribute>
            <xsl:apply-templates/>
          </a>
        </xsl:otherwise>
      </xsl:choose>
    </xsl:when>
    <xsl:otherwise><xsl:apply-templates/></xsl:otherwise>
  </xsl:choose>
</xsl:template>

</xsl:stylesheet>
